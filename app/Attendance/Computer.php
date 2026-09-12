<?php

namespace App\Attendance;

use App\Enums\WorkdayStatus;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Punch;
use App\Models\Timelog;
use App\Models\Workday;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class Computer
{
    public function __construct(
        private readonly Employee $employee,
        private readonly Settings $settings,
    ) {}

    public function over(CarbonInterface $from, CarbonInterface $to): void
    {
        $start = CarbonImmutable::parse($from->format('Y-m-d'));
        $end = CarbonImmutable::parse($to->format('Y-m-d'));

        if ($start->gt($end)) {
            return;
        }

        [$weekStart] = Week::bounds($start);
        [, $weekEnd] = Week::bounds($end);

        $resolutions = (new Resolver($this->employee))->over($weekStart, $weekEnd);

        foreach ($resolutions as $resolution) {
            $resolution?->roster->schedule->loadMissing('fallbackShift');
        }

        $almanac = Almanac::for($this->employee, $weekStart, $weekEnd);
        $calendar = new Calendar($almanac, $this->settings);
        $timelogs = $this->candidateTimelogs($start, $end);
        $employed = $this->employedDates($start, $end);
        $computed = [];

        $this->discardOrphans($start, $end, $employed);

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            if (! isset($employed[$date->toDateString()])) {
                continue;
            }

            $this->persist($calendar, $resolutions, $almanac, $timelogs, $date, $computed);
        }
    }

    /**
     * @param  array<string, true>  $employed
     */
    private function discardOrphans(CarbonImmutable $start, CarbonImmutable $end, array $employed): void
    {
        Workday::query()
            ->where('employee_id', $this->employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn(
                'ledger_id',
                Ledger::query()
                    ->select('id')
                    ->where('employee_id', $this->employee->id)
                    ->whereNull('locked_at'),
            )
            ->when($employed !== [], fn (Builder $kept) => $kept->whereNotIn('date', array_keys($employed)))
            ->delete();
    }

    /**
     * @return array<string, true>
     */
    private function employedDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $spans = Deployment::query()
            ->where('employee_id', $this->employee->id)
            ->where('starts', '<=', $to->toDateString())
            ->where(fn (Builder $ended) => $ended->whereNull('ends')->orWhere('ends', '>=', $from->toDateString()))
            ->get(['starts', 'ends']);

        $dates = [];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $day = $date->toDateString();

            foreach ($spans as $span) {
                $ends = $span->ends?->toDateString();

                if ($span->starts->toDateString() <= $day && ($ends === null || $ends >= $day)) {
                    $dates[$day] = true;

                    break;
                }
            }
        }

        return $dates;
    }

    /**
     * @param  array<string, Resolution|null>  $resolutions
     * @param  Collection<int, Timelog>  $timelogs
     * @param  array<string, array{status: WorkdayStatus}>  $computed
     */
    private function persist(
        Calendar $calendar,
        array $resolutions,
        Almanac $almanac,
        Collection $timelogs,
        CarbonImmutable $date,
        array &$computed,
    ): void {
        $day = $calendar->apply($resolutions, $date);

        DB::transaction(function () use ($almanac, $timelogs, $date, $day, &$computed): void {
            $ledger = Ledger::firstOrCreate([
                'employee_id' => $this->employee->id,
                'month' => $date->startOfMonth()->toDateString(),
            ]);

            if ($ledger->locked()) {
                return;
            }

            $matching = Matcher::match(
                $day->sides,
                $this->unclaimed($timelogs, $date),
                (int) ($day->shift?->flex ?? 0),
                (bool) ($day->shift?->trust ?? false),
                $date,
            );

            $derived = Deriver::derive(
                $day,
                $matching,
                $this->settings->missingSide(),
                $this->settings->premiumHours(),
                $this->settings->suspensionCharge(),
                $this->precedingUnexcusedAbsence($date, $computed),
            );

            $workday = Workday::updateOrCreate(
                [
                    'employee_id' => $this->employee->id,
                    'date' => $date->toDateString(),
                ],
                [
                    'ledger_id' => $ledger->id,
                    'shift_id' => $day->shift?->id,
                    'shift' => Snapshot::of(
                        $day,
                        $this->settings,
                        $almanac->holidays($date),
                        $almanac->suspensions($date),
                    ),
                    'exemption_id' => $day->exemptionId,
                    'status' => $derived->status,
                    'premium' => $day->premium,
                    'worked' => $derived->worked,
                    'credited' => $derived->credited,
                    'tardy' => $derived->tardy,
                    'undertime' => $derived->undertime,
                    'excess' => $derived->excess,
                    'night' => $derived->night,
                    'night_excess' => $derived->nightExcess,
                    'computed_at' => now(),
                ],
            );

            $workday->punches()->delete();
            $workday->punches()->createMany($this->punchRows($matching));

            $computed[$date->toDateString()] = ['status' => $derived->status];
        });
    }

    /**
     * @return Collection<int, Timelog>
     */
    private function candidateTimelogs(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Timelog::query()
            ->where('employee_id', $this->employee->id)
            ->standing()
            ->where('time', '>=', $from->subDay()->startOfDay()->toDateTimeString())
            ->where('time', '<=', $to->addDays(4)->endOfDay()->toDateTimeString())
            ->orderBy('time')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Timelog>  $timelogs
     * @return list<array{id: string, time: CarbonImmutable, state: int}>
     */
    private function unclaimed(Collection $timelogs, CarbonImmutable $date): array
    {
        $claimed = Punch::query()
            ->where('employee_id', $this->employee->id)
            ->whereNotNull('timelog_id')
            ->whereNotIn(
                'workday_id',
                Workday::query()
                    ->select('id')
                    ->where('employee_id', $this->employee->id)
                    ->whereDate('date', $date->toDateString()),
            )
            ->pluck('timelog_id');

        return $timelogs
            ->reject(fn (Timelog $timelog): bool => $claimed->contains($timelog->id))
            ->map(fn (Timelog $timelog): array => [
                'id' => $timelog->id,
                'time' => CarbonImmutable::parse($timelog->time->format('Y-m-d H:i:s')),
                'state' => (int) $timelog->state,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{status: WorkdayStatus}>  $computed
     */
    private function precedingUnexcusedAbsence(CarbonImmutable $date, array $computed): bool
    {
        for ($offset = 1; $offset <= 7; $offset++) {
            $previous = $date->subDays($offset)->toDateString();

            if (isset($computed[$previous])) {
                $status = $computed[$previous]['status'];
            } else {
                $status = Workday::query()
                    ->where('employee_id', $this->employee->id)
                    ->whereDate('date', $previous)
                    ->value('status');

                if ($status === null) {
                    continue;
                }
            }

            if (! $status->expectsWork()) {
                continue;
            }

            return $status === WorkdayStatus::Absent;
        }

        return false;
    }

    /**
     * @return list<array{employee_id: string, slot: int, kind: string, expected_at: ?string, timelog_id: ?string, actual_at: ?string, deviation: ?int}>
     */
    private function punchRows(Matching $matching): array
    {
        $rows = [];

        foreach ($matching->punches as $punch) {
            $rows[] = [
                'employee_id' => $this->employee->id,
                'slot' => $punch['slot'],
                'kind' => $punch['kind'],
                'expected_at' => $punch['expected_at']?->format('Y-m-d H:i:s'),
                'timelog_id' => $punch['timelog_id'],
                'actual_at' => $punch['actual_at']?->format('Y-m-d H:i:s'),
                'deviation' => $punch['deviation'],
            ];
        }

        return $rows;
    }
}
