<?php

namespace App\Attendance;

use App\Models\Employee;
use App\Models\Roster;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

final class Resolver
{
    public function __construct(private readonly Employee $employee) {}

    /**
     * @return array<string, Resolution|null>
     */
    public function over(CarbonInterface $from, CarbonInterface $to): array
    {
        $start = CarbonImmutable::parse($from->format('Y-m-d'));
        $end = CarbonImmutable::parse($to->format('Y-m-d'));

        if ($start->gt($end)) {
            return [];
        }

        $rosters = $this->employee->rosters()
            ->where('starts', '<=', $end->toDateString())
            ->where(fn (Builder $ended) => $ended->whereNull('ends')->orWhere('ends', '>=', $start->toDateString()))
            ->with('schedule.turns.shift')
            ->get();

        $resolved = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $resolved[$date->toDateString()] = $this->on($date, $rosters);
        }

        return $resolved;
    }

    /**
     * @param  Collection<int, Roster>  $rosters
     */
    private function on(CarbonImmutable $date, Collection $rosters): ?Resolution
    {
        $day = $date->toDateString();
        $roster = $rosters->first(
            fn (Roster $roster): bool => $roster->starts->toDateString() <= $day
                && ($roster->ends === null || $roster->ends->toDateString() >= $day),
        );

        if ($roster === null) {
            return null;
        }

        $position = Cycle::position($date, $roster->anchor, $roster->schedule->length);
        $turn = $roster->schedule->turns->firstWhere('position', $position);

        if ($turn === null) {
            throw new RuntimeException("Schedule {$roster->schedule_id} has no turn at position {$position}.");
        }

        return new Resolution($roster, $position, $turn->shift);
    }
}
