<?php

namespace App\Models;

use App\Attendance\Authorised;
use App\Attendance\LedgerView;
use App\Attendance\Week;
use App\Enums\MissingSide;
use App\Enums\Period;
use App\Enums\Work;
use App\Enums\WorkdayStatus;
use App\Models\Concerns\BelongsToAgency;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Factories\LedgerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['agency_id', 'employee_id', 'month', 'locked_at'])]
class Ledger extends Model
{
    /**
     * @use HasFactory<LedgerFactory>
     */
    use BelongsToAgency, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
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

        $loaded = Workday::query()

            ->when($includeOvertime, fn (Builder $query) => $query->with('punches'))
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
            $overtime = $this->compensableDaily($workdays, $authorities, $settings->overtimeGates())
                + $this->weeklyOnly($loaded, $from, $to, $ceiling);
        }

        if ($settings->occurrences()) {
            $tardyOccurrences = $workdays->filter(fn (Workday $workday): bool => $workday->tardy > 0)->count();
            $undertimeOccurrences = $workdays->filter(fn (Workday $workday): bool => $workday->undertime > 0)->count();

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
    private function compensableDaily(Collection $workdays, Collection $authorities, bool $gated): int
    {
        if (! $gated) {
            return (int) $workdays->sum('excess');
        }

        $windows = $authorities
            ->map(fn (Overtime $overtime): array => [
                CarbonImmutable::parse($overtime->starts->format('Y-m-d H:i:s')),
                CarbonImmutable::parse($overtime->ends->format('Y-m-d H:i:s')),
            ])
            ->values()
            ->all();

        $overtime = 0;

        foreach ($workdays as $workday) {

            if ($workday->tardy !== 0) {
                continue;
            }

            if ($workday->excess < 120) {
                continue;
            }

            $authorised = Authorised::minutes(
                $workday->punches,
                MissingSide::tryFrom($workday->shift['settings']['missing_side'] ?? '') ?? MissingSide::Void,
                (int) $workday->credited,
                $windows,
            );

            $overtime += $workday->premium !== null
                ? min($authorised, 720)
                : $authorised;
        }

        return $overtime;
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
