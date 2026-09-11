<?php

namespace App\Http\Resources;

use App\Models\Terminal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Terminal` interface in resources/js/types/index.d.ts.
 *
 * **`secret` is never sent.** It is the device comm key, `encrypted`-cast on
 * the model, and decision 40 forbids it leaving the database at all — the
 * predecessor leaked the same value through five channels, one of which was
 * simply putting it where a client could read it. The form reports whether a
 * key is *set* (`has_secret`) and nothing more, which is all an operator needs
 * to know.
 *
 * `code` is a string and stays one (decision 42): it is the number the attlog
 * speaks, and JSON would happily make it a number.
 *
 * @mixin Terminal
 */
class TerminalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workgroup_id' => $this->workgroup_id,
            // `whenLoaded` alone is not enough: `workgroup_id` is nullable —
            // an agency-wide terminal in a shared lobby has none — so the
            // relation loads as null and WorkgroupResource::make(null) reads
            // ->id off nothing. The list's commonest row is exactly this one.
            'workgroup' => $this->whenLoaded(
                'workgroup',
                fn () => $this->workgroup === null ? null : WorkgroupResource::make($this->workgroup)->resolve(),
            ),
            'code' => $this->code,
            'name' => $this->name,
            'serial' => $this->serial,
            'kind' => $this->kind->value,
            'protocol' => $this->protocol->value,
            'host' => $this->host,
            'port' => $this->port,
            // Whether a comm key exists, never the key. See the class docblock.
            'has_secret' => $this->secret !== null,
            'active' => $this->active,
            // Dates cross the wire as strings; the TS side never reparses them
            // (.ai/rules/resources.md).
            'seen_at' => $this->seen_at?->toDateTimeString(),
            'synced_at' => $this->synced_at?->toDateTimeString(),
            // The read offset for a future pull. Exposed because "has this
            // device ever been read" is a question the list answers, and
            // because an import must never move it (decision 40).
            'stamp' => $this->stamp,
            // Absent everywhere the query did not ask, so a missing count can
            // never read as zero (.ai/rules/resources.md). Aliased for what
            // they answer rather than for the relation they walk: "how many
            // people can this device identify today" and "has it ever
            // captured anything", which are two different questions.
            'enrolled_count' => $this->whenCounted('enrolled'),
            'timelogs_count' => $this->whenCounted('timelogs'),
            // `whenHas`, not `whenNotNull`: the latter evaluates its argument
            // eagerly, so on a terminal loaded without the index's `withMax`
            // — `edit`, or the enrollments page — Model::shouldBeStrict()
            // throws on an attribute the query never selected. `whenHas` asks
            // the model whether the key is there at all, which is the actual
            // question. Absent still never reads as "never".
            'last_import_at' => $this->whenHas('last_import_at'),
        ];
    }
}
