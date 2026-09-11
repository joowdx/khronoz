<?php

namespace App\Models;

use App\Attendance\LedgerView;
use App\Attendance\Week;
use App\Enums\Period;
use App\Enums\Work;
use App\Enums\WorkdayStatus;
use App\Models\Concerns\BelongsToAgency;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Factories\LedgerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One employee-month: the DTR page with a lock on it
 * (docs/design/06-attendance.md Ledger rules 1–3). Created by the first
 * workday computed in that month. Stores `locked_at` only — totals, the
 * monthly occurrence counts and compensable overtime are derived at read
 * time by view() and never stored.
 */
#[Fillable(['agency_id', 'employee_id', 'month', 'locked_at'])]
class Ledger extends Model
{
    /** @use HasFactory<LedgerFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'locked_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workdays(): HasMany
    {
        return $this->hasMany(Workday::class);
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(Attestation::class);
    }

    public function locked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * The workdays of a period, their totals, the monthly occurrence
     * counts, and the compensable overtime. A null $work means both
     * classes (Ledger rule 2).
     *
     * $work governs the overtime figure, not the seven minute totals:
     * Work::Regular reports overtime as 0 and still sums worked, credited,
     * tardy, undertime, excess, night and nightExcess over the period.
     * Decision 72's closing paragraph leaves the harder version of this
     * question — what an overtime-only view should show when some daily
     * excess is unauthorised — deliberately open.
     */
    public function view(Period $period, ?Work $work = null): LedgerView
    {
        $this->loadMissing('agency');

        [$from, $to] = $this->periodBounds($period);
        $settings = new Settings($this->agency);
        $includeOvertime = $work !== Work::Regular;
        $ceiling = $includeOvertime ? $settings->overtimeAfterWeekly() : null;

        $loadFrom = $from;
        $loadTo = $to;

        if ($ceiling !== null) {
            $loadFrom = Week::bounds($from)[0];
            $loadTo = Week::bounds($to)[1];
        }

        // One query by employee and date, never $this->workdays: a week
        // straddling the month end draws from the neighbouring ledger
        // (decision 52). The period slice is then a filter in PHP.
        $loaded = Workday::query()
            ->where('employee_id', $this->employee_id)
            ->whereBetween('date', [$loadFrom->toDateString(), $loadTo->toDateString()])
            ->orderBy('date')
            ->get();

        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $workdays = $loaded
            ->filter(function (Workday $workday) use ($fromDate, $toDate): bool {
                $date = $workday->date->toDateString();

                return $date >= $fromDate && $date <= $toDate;
            })
            ->values();

        $authorities = $includeOvertime
            ? Overtime::query()
                ->where('employee_id', $this->employee_id)
                ->overlapping($from, $to->addDay())
                ->get()
            : collect();

        $overtime = 0;

        if ($includeOvertime) {
            $overtime = $this->compensableDaily($workdays, $authorities)
                + $this->weeklyOnly($loaded, $from, $to, $ceiling);
        }

        if ($settings->occurrences()) {
            $tardyOccurrences = $workdays->filter(fn (Workday $workday): bool => $workday->tardy > 0)->count();
            $undertimeOccurrences = $workdays->filter(fn (Workday $workday): bool => $workday->undertime > 0)->count();
            // Decision 77: `absent` already means unexcused, so the status
            // is the whole test. Calendar::status() returns `Exempt` for
            // any day carrying a **whole-day** excusing exemption, so a
            // day that reaches `Absent` has none by construction and every
            // excusing exemption still on it covers part of the day. Asking
            // the stamp again read a two-hour excused pass on a day of no
            // attendance as a fully excused absence and dropped the count
            // to zero. Decision 73's insight — a stamp is not an excuse —
            // stands; the stamp is simply the wrong place to look for it.
            $absences = $workdays
                ->filter(fn (Workday $workday): bool => $workday->status === WorkdayStatus::Absent)
                ->count();
        } else {
            $tardyOccurrences = 0;
            $undertimeOccurrences = 0;
            $absences = 0;
        }

        return new LedgerView(
            workdays: $workdays,
            worked: (int) $workdays->sum('worked'),
            credited: (int) $workdays->sum('credited'),
            tardy: (int) $workdays->sum('tardy'),
            undertime: (int) $workdays->sum('undertime'),
            excess: (int) $workdays->sum('excess'),
            night: (int) $workdays->sum('night'),
            nightExcess: (int) $workdays->sum('night_excess'),
            overtime: $overtime,
            tardyOccurrences: $tardyOccurrences,
            undertimeOccurrences: $undertimeOccurrences,
            absences: $absences,
        );
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodBounds(Period $period): array
    {
        $month = CarbonImmutable::parse($this->month->toDateString());

        return match ($period) {
            Period::First => [$month, $month->setDay(15)],
            Period::Second => [$month->setDay(16), $month->endOfMonth()->startOfDay()],
            Period::Full => [$month, $month->endOfMonth()->startOfDay()],
        };
    }

    /**
     * @param  Collection<int, Workday>  $workdays
     * @param  Collection<int, Overtime>  $authorities
     */
    private function compensableDaily(Collection $workdays, Collection $authorities): int
    {
        $overtime = 0;

        foreach ($workdays as $workday) {
            if (! $this->authorityCovers($workday, $authorities)) {
                continue;
            }

            // tardy === 0 is the gate, and it is not quite what §10.1 says.
            // The text is "arrive on or before the start of the workday",
            // and with a non-zero grace an employee can arrive after the
            // start and still have tardy 0. Use tardy === 0 anyway: CSC
            // creates no grace period at all (00-principles.md — grace is
            // "not a free choice"), so the two readings differ only in a
            // configuration the rule's own regime does not permit, and
            // reading the punches back to split that hair would cost a
            // query per day for a case that cannot arise where the rule
            // applies.
            if ($workday->tardy !== 0) {
                continue;
            }

            if ($workday->excess < 120) {
                continue;
            }

            $overtime += $workday->premium !== null
                ? min($workday->excess, 720)
                : $workday->excess;
        }

        return $overtime;
    }

    /**
     * @param  Collection<int, Overtime>  $authorities
     */
    private function authorityCovers(Workday $workday, Collection $authorities): bool
    {
        $from = CarbonImmutable::parse($workday->date->toDateString());
        $to = $from->addDay();

        return $authorities->contains(function (Overtime $overtime) use ($from, $to): bool {
            return $overtime->starts->lt($to) && $overtime->ends->gt($from);
        });
    }

    /**
     * @param  Collection<int, Workday>  $loaded
     */
    private function weeklyOnly(Collection $loaded, CarbonImmutable $from, CarbonImmutable $to, ?int $ceiling): int
    {
        if ($ceiling === null) {
            return 0;
        }

        $overtime = 0;
        $monday = Week::bounds($from)[0];
        $lastMonday = Week::bounds($to)[0];

        for ($weekStart = $monday; $weekStart->lte($lastMonday); $weekStart = $weekStart->addDays(7)) {
            $weekEnd = $weekStart->addDays(6);

            // Decision 75, at the period's resolution (decision 76): a
            // week's weeklyOnly belongs to the view containing its Sunday,
            // so no week is paid on two DTRs. Two consequences that look
            // like bugs otherwise: a month's first week may reach back
            // into the previous month and still counts those minutes
            // toward this week's ceiling — correct, the ceiling is a
            // property of the week, not of the month (decision 52). A
            // month's last partial week is reported by the next month,
            // not this one, which is also why this month can lock: every
            // week it reports is complete before it ends. The same
            // argument one resolution down: a week ending on the 20th is
            // not knowable on the 15th — days 16 to 20 have not happened
            // when the first-half payroll runs — so Period::First must
            // not report it.
            if ($weekEnd->lt($from) || $weekEnd->gt($to)) {
                continue;
            }

            $start = $weekStart->toDateString();
            $end = $weekEnd->toDateString();
            $days = $loaded->filter(function (Workday $workday) use ($start, $end): bool {
                $date = $workday->date->toDateString();

                return $date >= $start && $date <= $end;
            });

            $overtime += Week::of(
                $days->map(fn (Workday $workday): array => [
                    'worked' => $workday->worked,
                    'credited' => $workday->credited,
                    'excess' => $workday->excess,
                ])->values()->all(),
                $ceiling,
            )->weeklyOnly();
        }

        return $overtime;
    }
}
