<?php

namespace App\Http\Resources;

use App\Models\Timelog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Timelog` interface in resources/js/types/index.d.ts.
 *
 * `state` and `mode` cross as the **raw attlog integers** (03-terminals.md
 * rule 6). They are not mapped to labels here: an unfamiliar firmware emits
 * codes the documented table does not list, and a server-side enum would have
 * to choose between refusing them and inventing a name. The front end labels
 * what it recognises and prints the number otherwise, which loses nothing.
 *
 * `time` is a naive local wall clock as the device reported it — never
 * converted, never adjusted for drift (rule 4) — so it crosses as a string and
 * is never reparsed (.ai/rules/resources.md).
 *
 * @mixin Timelog
 */
class TimelogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'terminal_id' => $this->terminal_id,
            'terminal' => $this->whenLoaded(
                'terminal',
                fn () => $this->terminal === null ? null : TerminalResource::make($this->terminal)->resolve(),
            ),
            'uid' => $this->uid,
            'time' => $this->time->toDateTimeString(),
            'state' => $this->state,
            'mode' => $this->mode,
            'source' => $this->source->value,
            // Null means unresolved, which is a normal and visible state — not
            // an error and never hidden.
            'employee_id' => $this->employee_id,
            // `whenLoaded` alone is not enough: `employee_id` is nullable and
            // an unresolved punch is the row this screen exists to show, so
            // EmployeeResource::make(null) would crash on the commonest case
            // the filter is built for.
            'employee' => $this->whenLoaded(
                'employee',
                fn () => $this->employee === null ? null : EmployeeResource::make($this->employee)->resolve(),
            ),
            'voided_at' => $this->voided_at?->toDateTimeString(),
            'reason' => $this->reason,
            // Who struck it out. The whole point of `voided_by` is that the
            // strike-out of a pay record is attributable, and an attribution
            // nobody can read is not one. `{id, name}` rather than a
            // UserResource, matching SuspensionResource::user.
            'voider' => $this->whenLoaded(
                'voider',
                fn () => $this->voider === null ? null : ['id' => $this->voider->id, 'name' => $this->voider->name],
            ),
        ];
    }
}
