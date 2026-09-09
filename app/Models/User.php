<?php

namespace App\Models;

use App\Enums\Permission;
use App\Models\Scopes\NotPlatformScope;
use App\Tenancy\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['agency_id', 'employee_id', 'name', 'email', 'password', 'permissions', 'invited_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'invited_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => AsEnumCollection::of(Permission::class),
        ];
    }

    /** Stored lower-cased: the unique index is on lower(email) and the password broker compares exact strings. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => Str::lower(trim($value)));
    }

    /** No tenant scope on User: authentication resolves users before any tenant exists (Task 6 explains). */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class)->withoutGlobalScope(NotPlatformScope::class);
    }

    /**
     * A user of the platform agency is a superuser (docs/design/02-access.md
     * rule 3). Compares agency_id directly rather than reading the agency
     * relation: Model::shouldBeStrict() (AppServiceProvider) throws on lazy
     * loading outside production, and Gate::before calls this on every
     * authorization check with whatever model the session guard supplies,
     * which is not always a freshly-created (and so lazy-load-exempt) one.
     */
    public function isPlatform(): bool
    {
        return $this->agency_id === app(Tenant::class)->platformId();
    }

    /**
     * `{user}` bindings never cross agencies even though User carries no
     * global scope. Guest routes (invite, verification) run with no tenant
     * and keep the plain lookup; their URLs are signed instead.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $tenant = app(Tenant::class);
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        return $tenant->check() ? $query->where('agency_id', $tenant->id()) : $query;
    }

    /** Whether this user holds $permission directly, or holds one that implies it. */
    public function allows(Permission $permission): bool
    {
        return $this->permissions->contains(fn (Permission $held) => $held->grants($permission));
    }
}
