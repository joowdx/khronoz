<?php

namespace App\Attendance;

use App\Models\Employee;
use App\Models\Roster;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * For one employee over a range of dates, which shift the roster puts on
 * each date (04-scheduling.md, Resolution for employee E on date D,
 * steps 1 to 3).
 *
 * The calendar does not enter here. Holidays, suspensions, exemptions and
 * rule 5's compressed-week fallback all need the calendar and a whole ISO
 * week, and belong to the next chunk. This class resolves the turn the
 * roster names and stops.
 *
 * It also does not gate on employment. "No roster" and "not employed" are
 * different outcomes: a date with no roster still gets a workday (raw
 * timelogs); a date outside employment should get none. Which dates get a
 * workday is the orchestrator's question.
 *
 * One query for the overlapping rosters, with `schedule.turns.shift`
 * eager-loaded, then the dates are walked in PHP — so sixty days cost
 * the same as one. Model::shouldBeStrict() would catch a lazy load, but
 * not a query issued eagerly inside the loop.
 */
final class Resolver
{
    public function __construct(private readonly Employee $employee) {}

    /**
     * One entry per date in the inclusive range, keyed `'Y-m-d'`. Null
     * means no roster covers that date. `from` after `to` is an empty
     * range and returns `[]`.
     *
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
     * The covering roster for $date, or null. At most one, by
     * `rosters_no_overlap`, so this is a `first()` over the loaded set and
     * not a pick-the-best. The predicate matches CoversDates::covering:
     * `starts <= D AND (ends IS NULL OR ends >= D)` — `ends` is inclusive.
     *
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

        // turns_complete guarantees 0 .. length - 1, so a miss is an
        // invariant violation — not "no shift", which means no roster.
        if ($turn === null) {
            throw new RuntimeException("Schedule {$roster->schedule_id} has no turn at position {$position}.");
        }

        return new Resolution($roster, $position, $turn->shift);
    }
}
