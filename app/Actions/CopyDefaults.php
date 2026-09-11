<?php

namespace App\Actions;

use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Scopes\AgencyScope;
use App\Models\Shift;
use App\Models\Turn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copy the platform agency's default shifts and schedules into $agency
 * (04-scheduling.md rule 7).
 *
 * **Three things make this more than an insert loop.**
 *
 * 1. *Platform rows are invisible to a tenant query.* `Shift` and `Schedule`
 *    carry the plain `AgencyScope` — unlike `Holiday`, which overrides
 *    `agencyScope()` — so `Shift::query()` can never return a default. Every
 *    read here drops that one scope and names `agency_id` itself, which is
 *    also why the turns are fetched by their own `whereIn` rather than by
 *    `with('turns')`: an eager load builds a *new* query on `Turn`, the scope
 *    applies to that one too, and under an agency's tenant a platform
 *    schedule comes back with an empty cycle — a silent wrong answer rather
 *    than an error.
 *
 * 2. *The copy has to remap foreign keys.* `turns` holds a paired FK
 *    `(shift_id, agency_id) REFERENCES shifts (id, agency_id)` and
 *    `schedules.fallback_shift_id` the same, so an agency's schedule cannot
 *    point at a platform shift at all. The shifts are copied first, the map
 *    `platform shift id => this agency's shift id` is built as they go, and
 *    every schedule's fallback and every turn's `shift_id` goes through it.
 *    `origin_id` is the one pointer that deliberately crosses agencies, and
 *    `origin_is_platform` proves it names a platform row.
 *
 * 3. *`turns_complete` is deferred.* It checks at COMMIT that a schedule has
 *    exactly `length` turns at positions 0..length-1, so a schedule and its
 *    turns must be written in **one** transaction — which is what the single
 *    `DB::transaction` around the whole run buys, and why nothing here
 *    commits per row.
 *
 * **Idempotent by `origin_id`**: a default this agency already has a copy of
 * is skipped, whatever the copy has since been renamed to. Running this twice
 * writes nothing the second time, and running it after the platform has added
 * a default copies only the addition.
 *
 * **A name already in use is left alone, never overwritten or suffixed.**
 * `shifts` and `schedules` are UNIQUE (agency_id, name), so a default named
 * `Standard` cannot be copied into an agency that already has a `Standard` of
 * its own. Refusing the whole run over one collision would make the action
 * useless to exactly the agencies that have started work; suffixing would
 * plant a `Standard (2)` nobody asked for, whose name no longer matches the
 * origin it claims. So the agency's own row stands, its name comes back in
 * `kept` for the flash to name, and — for a shift — that row becomes the map
 * target, so a schedule copied alongside it points at the shift of that name
 * this agency actually has. The screen goes on showing the default as
 * uncopied, which is the truth rather than a silent no-op.
 *
 * **Call it inside $agency's own tenant, or inside none.** `BelongsToAgency`
 * refuses an explicit `agency_id` that disagrees with a tenant already set
 * (`TenantMismatch`), so this cannot be run for one agency from inside
 * another's request.
 */
final class CopyDefaults
{
    /**
     * @return array{shifts: int, schedules: int, kept: list<string>}
     */
    public function handle(Agency $agency): array
    {
        return DB::transaction(function () use ($agency): array {
            $platform = Agency::platform();

            [$shifts, $keptShifts, $map] = $this->copyShifts($agency, $platform);
            [$schedules, $keptSchedules] = $this->copySchedules($agency, $platform, $map);

            return [
                'shifts' => $shifts,
                'schedules' => $schedules,
                'kept' => [...$keptShifts, ...$keptSchedules],
            ];
        });
    }

    /**
     * Copy the platform's shifts, and answer with the map every schedule and
     * turn is then remapped through.
     *
     * The map is total over the platform's shifts — a default is either
     * copied now, already copied, or answered by the agency's own row of the
     * same name — so a turn can always find its shift, which is what keeps
     * the deferred `turns_complete` satisfiable.
     *
     * @return array{0: int, 1: list<string>, 2: array<string, string>}
     */
    private function copyShifts(Agency $agency, Agency $platform): array
    {
        $defaults = $this->defaults(Shift::query(), $platform);
        $own = $this->own(Shift::query(), $agency);

        $byOrigin = $own->whereNotNull('origin_id')->keyBy('origin_id');
        $byName = $own->keyBy('name');

        $copied = 0;
        $kept = [];
        $map = [];

        foreach ($defaults as $default) {
            if ($existing = $byOrigin->get($default->id)) {
                $map[$default->id] = $existing->id;

                continue;
            }

            if ($existing = $byName->get($default->name)) {
                $map[$default->id] = $existing->id;
                $kept[] = $default->name;

                continue;
            }

            $copy = Shift::create([
                'agency_id' => $agency->id,
                'name' => $default->name,
                'slots' => $default->slots,
                'required' => $default->required,
                'flex' => $default->flex,
                'remote' => $default->remote,
                'trust' => $default->trust,
                // Rule 8: a copy carries the origin's ramp index rather than
                // taking the lowest free one, so one default is one colour
                // across every agency.
                'color' => $default->color,
                'origin_id' => $default->id,
            ]);

            $map[$default->id] = $copy->id;
            $copied++;
        }

        return [$copied, $kept, $map];
    }

    /**
     * Copy the platform's schedules and their turns, remapping every shift
     * reference through $map.
     *
     * @param  array<string, string>  $map  platform shift id => this agency's shift id
     * @return array{0: int, 1: list<string>}
     */
    private function copySchedules(Agency $agency, Agency $platform, array $map): array
    {
        $defaults = $this->defaults(Schedule::query(), $platform);
        $own = $this->own(Schedule::query(), $agency);

        $byOrigin = $own->whereNotNull('origin_id')->keyBy('origin_id');
        $byName = $own->keyBy('name');
        $cycles = $this->cyclesOf($defaults);

        $copied = 0;
        $kept = [];

        foreach ($defaults as $default) {
            if ($byOrigin->has($default->id)) {
                continue;
            }

            if ($byName->has($default->name)) {
                $kept[] = $default->name;

                continue;
            }

            /** @var EloquentCollection<int, Turn> $cycle */
            $cycle = $cycles->get($default->id, new EloquentCollection);

            // Defensive, and it cannot fire against valid platform data: the
            // map is total over the platform's shifts, and `turns_complete`
            // already holds on the origin. If it ever did, writing a short
            // cycle would raise P0001 at COMMIT and take the whole run with
            // it — so this schedule is skipped instead.
            if ($cycle->count() !== $default->length || $cycle->contains(fn (Turn $turn) => ! isset($map[$turn->shift_id]))) {
                continue;
            }

            $copy = Schedule::create([
                'agency_id' => $agency->id,
                'name' => $default->name,
                'length' => $default->length,
                'fallback_shift_id' => $default->fallback_shift_id === null
                    ? null
                    : ($map[$default->fallback_shift_id] ?? null),
                'origin_id' => $default->id,
            ]);

            foreach ($cycle as $turn) {
                Turn::create([
                    'agency_id' => $agency->id,
                    'schedule_id' => $copy->id,
                    'shift_id' => $map[$turn->shift_id],
                    'position' => $turn->position,
                ]);
            }

            $copied++;
        }

        return [$copied, $kept];
    }

    /**
     * The turns of $schedules, grouped by schedule and in cycle order.
     *
     * @param  EloquentCollection<int, Schedule>  $schedules
     * @return Collection<string, EloquentCollection<int, Turn>>
     */
    private function cyclesOf(EloquentCollection $schedules): Collection
    {
        return Turn::query()
            ->withoutGlobalScopes([AgencyScope::class])
            ->whereIn('schedule_id', $schedules->modelKeys())
            ->orderBy('position')
            ->get()
            ->groupBy('schedule_id');
    }

    /**
     * The platform agency's rows of $query's model — the defaults themselves,
     * which no tenant query can see.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return EloquentCollection<int, TModel>
     */
    private function defaults(Builder $query, Agency $platform): EloquentCollection
    {
        return $query
            ->withoutGlobalScopes([AgencyScope::class])
            ->where('agency_id', $platform->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * This agency's own rows of $query's model. Named explicitly rather than
     * left to the scope, so the action is still right from a console run that
     * has no tenant at all.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return EloquentCollection<int, TModel>
     */
    private function own(Builder $query, Agency $agency): EloquentCollection
    {
        return $query
            ->withoutGlobalScopes([AgencyScope::class])
            ->where('agency_id', $agency->id)
            ->get();
    }
}
