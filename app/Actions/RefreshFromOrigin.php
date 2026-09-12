<?php

namespace App\Actions;

use App\Models\Schedule;
use App\Models\Scopes\AgencyScope;
use App\Models\Shift;
use App\Models\Turn;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

final class RefreshFromOrigin
{
    public function handle(Shift|Schedule $copy): bool
    {
        return DB::transaction(function () use ($copy): bool {

            $origin = $copy->origin()->withoutGlobalScopes([AgencyScope::class])->first();

            if ($origin === null) {
                return false;
            }

            return $copy instanceof Shift
                ? $this->refreshShift($copy, $origin)
                : $this->refreshSchedule($copy, $origin);
        });
    }

    private function refreshShift(Shift $copy, Shift $origin): bool
    {
        $copy->update([
            'slots' => $origin->slots,
            'required' => $origin->required,
            'flex' => $origin->flex,
            'remote' => $origin->remote,
            'trust' => $origin->trust,
        ]);

        return true;
    }

    private function refreshSchedule(Schedule $copy, Schedule $origin): bool
    {
        $cycle = Turn::query()
            ->withoutGlobalScopes([AgencyScope::class])
            ->where('schedule_id', $origin->id)
            ->orderBy('position')
            ->get();

        if ($cycle->count() !== $origin->length) {
            return false;
        }

        $map = $this->shiftsFor($copy, $cycle);

        if ($map === null) {
            return false;
        }

        $copy->update(['length' => $origin->length]);

        Turn::query()
            ->withoutGlobalScopes([AgencyScope::class])
            ->where('agency_id', $copy->agency_id)
            ->where('schedule_id', $copy->id)
            ->delete();

        foreach ($cycle as $turn) {
            Turn::create([
                'agency_id' => $copy->agency_id,
                'schedule_id' => $copy->id,
                'shift_id' => $map[$turn->shift_id],
                'position' => $turn->position,
            ]);
        }

        return true;
    }

    /**
     * @param  EloquentCollection<int, Turn>  $cycle
     * @return array<string, string>|null
     */
    private function shiftsFor(Schedule $copy, EloquentCollection $cycle): ?array
    {
        $wanted = Shift::query()
            ->withoutGlobalScopes([AgencyScope::class])
            ->whereIn('id', $cycle->pluck('shift_id')->unique()->all())
            ->get();

        $own = Shift::query()
            ->withoutGlobalScopes([AgencyScope::class])
            ->where('agency_id', $copy->agency_id)
            ->get();

        $byOrigin = $own->whereNotNull('origin_id')->keyBy('origin_id');
        $byName = $own->keyBy('name');

        $map = [];

        foreach ($wanted as $shift) {
            $match = $byOrigin->get($shift->id) ?? $byName->get($shift->name);

            if ($match === null) {
                return null;
            }

            $map[$shift->id] = $match->id;
        }

        return $map;
    }
}
