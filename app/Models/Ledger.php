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
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['agency_id', 'employee_id', 'cadence_id', 'month', 'starts', 'ends', 'scope', 'revision', 'locked_at', 'locked_by', 'unlocked_at', 'unlocked_by', 'calculation', 'identity', 'policy', 'signers'])]
class Ledger extends Model
{
    protected $attributes = ['scope' => 'all', 'locked_at' => null, 'unlocked_at' => null];

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
            'starts' => 'immutable_date',
            'ends' => 'immutable_date',
            'scope' => Work::class,
            'revision' => 'integer',
            'locked_at' => 'datetime',
            'unlocked_at' => 'datetime',
            'calculation' => 'array',
            'identity' => 'array',
            'policy' => 'array',
            'signers' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workdays(): HasMany
    {
        return $this->hasMany(Workday::class, 'employee_id', 'employee_id')
            ->whereBetween('date', [$this->starts->toDateString(), $this->ends->toDateString()]);
    }

    public function cadence(): BelongsTo
    {
        return $this->belongsTo(Cadence::class);
    }

    public function renditions(): HasMany
    {
        return $this->hasMany(Rendition::class);
    }

    protected function month(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->starts->startOfMonth(),
            set: fn ($value) => [
                'starts' => CarbonImmutable::parse($value)->startOfMonth()->toDateString(),
                'ends' => CarbonImmutable::parse($value)->endOfMonth()->toDateString(),
            ],
        );
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(Attestation::class);
    }

    public function locked(): bool
    {
        return $this->getRawOriginal('locked_at') !== null && $this->getRawOriginal('unlocked_at') === null;
    }

    public function rangeView(?Work $work = null): LedgerView
    {
        return $this->view(Period::Full, $work ?? $this->scope);
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

        $overtimeByDate = [];

        if ($includeOvertime) {
            $overtimeByDate = $this->compensableByDate($workdays, $authorities, $settings->overtimeGates());

            foreach ($this->weeklyOnlyByDate($loaded, $from, $to, $ceiling) as $date => $minutes) {
                $overtimeByDate[$date] = ($overtimeByDate[$date] ?? 0) + $minutes;
            }
        }

        $workdays->each(function (Workday $workday) use ($overtimeByDate): void {
            $workday->setAttribute('overtime', $overtimeByDate[$workday->date->toDateString()] ?? 0);
        });

        $overtime = array_sum($overtimeByDate);

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
            overtimeByDate: $overtimeByDate,
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
        $month = CarbonImmutable::parse($this->starts->toDateString())->startOfMonth();

        return match ($period) {
            Period::First => [$month, $month->setDay(15)],
            Period::Second => [$month->setDay(16), $month->endOfMonth()->startOfDay()],
            Period::Full => [$this->starts, $this->ends],
        };
    }

    /**
     * @param  Collection<int, Workday>  $workdays
     * @param  Collection<int, Overtime>  $authorities
     * @return array<string, int>
     */
    private function compensableByDate(Collection $workdays, Collection $authorities, bool $gated): array
    {
        if (! $gated) {
            return $workdays
                ->filter(fn (Workday $workday): bool => $workday->excess > 0)
                ->mapWithKeys(fn (Workday $workday): array => [$workday->date->toDateString() => (int) $workday->excess])
                ->all();
        }

        $windows = $authorities
            ->map(fn (Overtime $overtime): array => [
                CarbonImmutable::parse($overtime->starts->format('Y-m-d H:i:s')),
                CarbonImmutable::parse($overtime->ends->format('Y-m-d H:i:s')),
            ])
            ->values()
            ->all();

        $overtime = [];

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

            $minutes = $workday->premium !== null
                ? min($authorised, 720)
                : $authorised;

            if ($minutes > 0) {
                $overtime[$workday->date->toDateString()] = $minutes;
            }
        }

        return $overtime;
    }

    /**
     * @param  Collection<int, Workday>  $loaded
     * @return array<string, int>
     */
    private function weeklyOnlyByDate(Collection $loaded, CarbonImmutable $from, CarbonImmutable $to, ?int $ceiling): array
    {
        if ($ceiling === null) {
            return [];
        }

        $overtime = [];
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

            $minutes = Week::of(
                $days->map(fn (Workday $workday): array => [
                    'worked' => $workday->worked,
                    'credited' => $workday->credited,
                    'excess' => $workday->excess,
                ])->values()->all(),
                $ceiling,
            )->weeklyOnly();

            if ($minutes > 0) {
                $overtime[$weekEnd->toDateString()] = $minutes;
            }
        }

        return $overtime;
    }
}
