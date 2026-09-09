<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Enums\Preset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Matches the `AuthUser` interface in resources/js/types/index.d.ts, and its
 * `User` extension: `permissions` crosses the wire as plain permission values
 * (not enum objects) and `platform` as a real boolean, not the agency_id it is
 * derived from.
 *
 * The users list needs four things a permission array alone does not say, so
 * they are computed once here rather than three times in the browser — the
 * list, the Access filter and the row menu all have to agree about them:
 *
 * | Key              | Is                                                        |
 * | ---------------- | --------------------------------------------------------- |
 * | `access`         | the preset's name when the held set *is* a preset, else a count |
 * | `status`         | `invited` until the invitation is accepted, then `active`  |
 * | `invitation_url` | the same signed accept link the invitation email carries   |
 * | `permission_groups` | the held permissions grouped by area, with their labels |
 *
 * `permissions` stays a flat array of values so useCan() and the shared
 * `auth.user` prop keep working unchanged. The list row's own shape is
 * `UserRow` in resources/js/pages/users/index.tsx, which extends `User` with
 * these keys.
 *
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'permissions' => $this->permissions->map(fn (Permission $permission) => $permission->value)->all(),
            'permission_groups' => $this->permissions
                ->groupBy(fn (Permission $permission) => $permission->group())
                ->map(fn ($permissions) => $permissions->map(fn (Permission $permission) => [
                    'value' => $permission->value,
                    'label' => $permission->label(),
                ])->values()->all())
                ->all(),
            'access' => $this->access(),
            'status' => $this->status(),
            'invitation_url' => $this->invitationUrl(),
            'platform' => $this->isPlatform(),
            'employee_id' => $this->employee_id,
            'invited_at' => $this->invited_at,
            'email_verified_at' => $this->email_verified_at,
        ];
    }

    /**
     * What the list's Access column reads. A preset is not stored (docs/
     * design/02-access.md rule 4), so "Admin" is a fact about the set rather
     * than a field: it is only that preset's name while the held set is
     * exactly that preset's bundle, and anything else is honestly reported
     * as a count.
     *
     * @return array{label: string, preset: string|null}
     */
    private function access(): array
    {
        $preset = $this->matchingPreset();
        $count = $this->permissions->count();

        return [
            'label' => match (true) {
                $preset !== null => $preset->label(),
                $count === 0 => 'No permissions',
                default => $count.' '.Str::plural('permission', $count),
            },
            'preset' => $preset?->value,
        ];
    }

    private function matchingPreset(): ?Preset
    {
        $held = $this->permissions->map(fn (Permission $permission) => $permission->value)->sort()->values()->all();

        foreach (Preset::cases() as $preset) {
            $bundle = collect($preset->permissions())
                ->map(fn (Permission $permission) => $permission->value)
                ->sort()->values()->all();

            if ($held === $bundle) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * Two states, because two are all this application can produce.
     * `email_verified_at` is the whole test — accepting an invitation is
     * what verifies the address — and it is the same test
     * UserInviteController::store() applies before re-sending.
     */
    private function status(): string
    {
        return $this->email_verified_at === null ? 'invited' : 'active';
    }

    /**
     * The row menu's "Copy invitation link", for an invitation nobody has
     * accepted yet. It is the same 7-day signed URL
     * InviteNotification mints, for the case where the email did not arrive
     * and the address has to be reached another way.
     *
     * Null once the invitation is accepted, which is also what keeps it out
     * of the shared `auth.user` prop on every page behind the `verified`
     * middleware — an accepted invitation has no link left to copy.
     */
    private function invitationUrl(): ?string
    {
        if ($this->email_verified_at !== null) {
            return null;
        }

        return URL::temporarySignedRoute('invite.accept', now()->addDays(7), ['user' => $this->resource]);
    }
}
