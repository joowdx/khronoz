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

                'color' => $default->color,
                'origin_id' => $default->id,
            ]);

            $map[$default->id] = $copy->id;
            $copied++;
        }

        return [$copied, $kept, $map];
    }

    /**
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
