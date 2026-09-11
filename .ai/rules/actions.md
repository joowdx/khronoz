---
paths:
  - 'app/Actions/**'
---

# Actions

## Actions are single-verb handle() classes
An Action is a final class named for the verb it performs (CreateAgency, InviteUser), with exactly one public handle() method that a controller calls. No other public surface — a second entry point belongs in a second Action, not a second public method.

## Removal preserves only placements that began
Decision 28: employment history consists solely of deployment ranges. Rehire is MoveEmployee after a gap; no hired_at/separated_at columns or SeparateEmployee action exist. RemoveEmployee owns one transaction: use bare today(), close the open placement to today if starts <= today, delete a never-started placement if starts > today, leave closed rows alone, then soft-delete the employee. Closing real history keeps the workgroup's delete restriction; deleting a never-started row releases it.

## Reading platform defaults: drop AgencyScope on every query, including eager loads
`Shift`/`Schedule`/`Turn` use the plain `AgencyScope`, so a platform-owned default is invisible to a tenant query. Reach it with `withoutGlobalScopes([AgencyScope::class])->where('agency_id', $platformId)` — and do NOT fix it with `with('turns')`: an eager load is a *fresh* query on `Turn` that the scope applies to too, so a platform schedule comes back with an empty cycle instead of an error. Fetch related rows with their own `whereIn` + `withoutGlobalScopes`, or set the relation by hand. Same for `$copy->origin()` — the origin is a platform row, so without dropping the scope it reads as "this copy has no origin".

Copying a default into an agency must REMAP: `turns (shift_id, agency_id)` and `schedules.fallback_shift_id` are paired FKs, so an agency's schedule cannot point at a platform shift. Build `platform shift id => agency shift id` while copying the shifts and put the fallback and every turn through it. All of it in ONE `DB::transaction` — `turns_complete` is DEFERRABLE INITIALLY DEFERRED and checks the cycle at COMMIT.

`CopyDefaults` is idempotent by `origin_id`, and leaves an agency's same-named row alone (UNIQUE (agency_id, name)) rather than overwriting or suffixing it — that row then becomes the map target so schedules stay copyable. `RefreshFromOrigin` restores only what the defaults screen compares (shift: slots/required/flex/remote/trust; schedule: length + ordered turns), never `name` or `color`.
