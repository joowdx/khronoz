<?php

namespace App\Attendance;

use App\Enums\ExemptionType;
use App\Enums\HolidayType;
use App\Enums\Premium;
use App\Enums\PunchKind;
use App\Enums\WorkdayStatus;
use App\Models\Exemption;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\Suspension;
use App\Support\Minutes;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Apply the calendar to a resolved roster day: holidays, work
 * suspensions, exemptions and the compressed-week revert
 * (05-calendar.md, 06-attendance.md daily rules 1, 7, 8 and 10).
 *
 * No queries. The Almanac loaded what applies; this class says what
 * that does to the day. Matching and minute arithmetic are later.
 *
 * `schedule.fallbackShift` must already be loaded when
 * `fallback_shift_id` is set — Resolver does not eager-load it, and
 * Model::shouldBeStrict() will throw rather than N+1.
 */
final class Calendar
{
    public function __construct(
        private readonly Almanac $almanac,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<string, Resolution|null>  $resolutions
     */
    public function apply(array $resolutions, CarbonInterface $date): Day
    {
        $day = CarbonImmutable::parse($date->format('Y-m-d'));
        $this->requireWeek($resolutions, $day);

        $exemptions = $this->almanac->exemptions($day);
        [$excused, $travel, $exemptionId] = $this->exemptionState($exemptions, $day);

        $resolution = $resolutions[$day->toDateString()];

        if ($resolution === null) {
            return new Day(
                date: $day,
                shift: null,
                sides: [],
                status: WorkdayStatus::Off,
                premium: null,
                excused: $excused,
                travel: $travel,
                exemptionId: $exemptionId,
                nightFrom: $this->settings->nightFrom(),
            );
        }

        $shift = $this->effectiveShift($resolution, $resolutions, $day);
        $holidays = $this->almanac->holidays($day);
        $suspensions = $this->almanac->suspensions($day);

        $sides = Expectation::sides(['slots' => $shift->slots], $day);
        $sides = $this->removeHoliday($sides, $holidays);
        $sides = $this->truncate($sides, $suspensions, $day);

        return new Day(
            date: $day,
            shift: $shift,
            sides: $sides,
            status: $this->status($shift, $holidays, $suspensions, $exemptions),
            premium: $this->premium($shift, $holidays, $sides),
            excused: $excused,
            travel: $travel,
            exemptionId: $exemptionId,
            nightFrom: $this->settings->nightFrom(),
        );
    }

    /**
     * @param  array<string, Resolution|null>  $resolutions
     */
    private function requireWeek(array $resolutions, CarbonImmutable $date): void
    {
        [$monday, $sunday] = Week::bounds($date);

        for ($cursor = $monday; $cursor->lte($sunday); $cursor = $cursor->addDay()) {
            $key = $cursor->toDateString();

            if (! array_key_exists($key, $resolutions)) {
                throw new InvalidArgumentException("Missing date {$key}.");
            }
        }
    }

    /**
     * @param  array<string, Resolution|null>  $resolutions
     */
    private function effectiveShift(Resolution $resolution, array $resolutions, CarbonImmutable $date): Shift
    {
        $schedule = $resolution->roster->schedule;

        if ($schedule->fallback_shift_id === null) {
            return $resolution->shift;
        }

        $day = $date->toDateString();
        [$monday, $sunday] = Week::bounds($date);

        for ($cursor = $monday; $cursor->lte($sunday); $cursor = $cursor->addDay()) {
            $key = $cursor->toDateString();

            if ($key === $day) {
                continue;
            }

            $other = $resolutions[$key];

            if ($other === null || ! $this->isOffTurn($other->shift)) {
                continue;
            }

            foreach ($this->almanac->holidays($cursor) as $holiday) {
                if ($day > $holiday->declared_at->toDateString()) {
                    return $schedule->fallbackShift;
                }
            }

            foreach ($this->almanac->suspensions($cursor) as $suspension) {
                if ($day > $suspension->declared_at->toDateString()) {
                    return $schedule->fallbackShift;
                }
            }
        }

        return $resolution->shift;
    }

    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  Collection<int, Holiday>  $holidays
     * @return list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>
     */
    private function removeHoliday(array $sides, Collection $holidays): array
    {
        if ($holidays->contains(fn (Holiday $holiday): bool => ! $holiday->type->expectsWork())) {
            return [];
        }

        return $sides;
    }

    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  Collection<int, Suspension>  $suspensions
     * @return list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>
     */
    private function truncate(array $sides, Collection $suspensions, CarbonImmutable $date): array
    {
        foreach ($suspensions as $suspension) {
            if ($suspension->wholeDay()) {
                return [];
            }

            $sides = $this->clip($sides, $this->instant($date, $suspension->starts));
        }

        return $sides;
    }

    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @return list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>
     */
    private function clip(array $sides, CarbonImmutable $starts): array
    {
        $pairs = [];

        foreach ($sides as $side) {
            $pairs[$side['slot']][$side['kind']] = $side;
        }

        $clipped = [];

        foreach ($pairs as $pair) {
            $in = $pair[PunchKind::In->value];
            $out = $pair[PunchKind::Out->value];

            if ($in['at']->gte($starts)) {
                continue;
            }

            if ($out['at']->gt($starts)) {
                $out['at'] = $starts;
            }

            $clipped[] = $in;
            $clipped[] = $out;
        }

        return $clipped;
    }

    /**
     * @param  Collection<int, Holiday>  $holidays
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     */
    private function premium(Shift $shift, Collection $holidays, array $sides): ?Premium
    {
        if ($sides !== []) {
            return null;
        }

        $premium = $this->isOffTurn($shift) ? Premium::Rest : null;

        foreach ($holidays as $holiday) {
            $class = $this->holidayPremium($holiday);

            if ($class !== null) {
                $premium = $this->stronger($premium, $class);
            }
        }

        return $premium;
    }

    private function holidayPremium(Holiday $holiday): ?Premium
    {
        if ($holiday->type->expectsWork()) {
            return null;
        }

        return $holiday->type === HolidayType::Regular ? Premium::Regular : Premium::Special;
    }

    private function stronger(?Premium $current, Premium $candidate): Premium
    {
        $rank = [
            Premium::Rest->value => 0,
            Premium::Special->value => 1,
            Premium::Regular->value => 2,
        ];

        if ($current === null || $rank[$candidate->value] > $rank[$current->value]) {
            return $candidate;
        }

        return $current;
    }

    /**
     * @param  Collection<int, Holiday>  $holidays
     * @param  Collection<int, Suspension>  $suspensions
     * @param  Collection<int, Exemption>  $exemptions
     */
    private function status(
        Shift $shift,
        Collection $holidays,
        Collection $suspensions,
        Collection $exemptions,
    ): ?WorkdayStatus {
        if ($this->isOffTurn($shift)) {
            return WorkdayStatus::Off;
        }

        if ($holidays->contains(fn (Holiday $holiday): bool => ! $holiday->type->expectsWork())) {
            return WorkdayStatus::Holiday;
        }

        if ($suspensions->contains(fn (Suspension $suspension): bool => $suspension->wholeDay())) {
            return WorkdayStatus::Suspended;
        }

        if ($exemptions->contains(fn (Exemption $exemption): bool => $exemption->wholeDay() && $exemption->excused())) {
            return WorkdayStatus::Exempt;
        }

        if ($shift->remote) {
            return WorkdayStatus::Remote;
        }

        return null;
    }

    /**
     * @param  Collection<int, Exemption>  $exemptions
     * @return array{0: list<array{0: CarbonImmutable, 1: CarbonImmutable}>, 1: bool, 2: ?string}
     */
    private function exemptionState(Collection $exemptions, CarbonImmutable $date): array
    {
        $excused = [];

        foreach ($exemptions as $exemption) {
            if ($exemption->excused()) {
                $excused[] = $this->window($exemption, $date);
            }
        }

        $travel = $exemptions->contains(
            fn (Exemption $exemption): bool => $exemption->type === ExemptionType::Travel,
        );

        $stamp = $exemptions->sort(function (Exemption $left, Exemption $right) use ($date): int {
            if ($left->excused() !== $right->excused()) {
                return $left->excused() ? -1 : 1;
            }

            $minutes = $this->coveredMinutes($right, $date) <=> $this->coveredMinutes($left, $date);

            if ($minutes !== 0) {
                return $minutes;
            }

            $approved = $left->approved_at <=> $right->approved_at;

            if ($approved !== 0) {
                return $approved;
            }

            return $left->id <=> $right->id;
        })->first();

        return [$excused, $travel, $stamp?->id];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(Exemption $exemption, CarbonImmutable $date): array
    {
        if ($exemption->wholeDay()) {
            $start = $date->startOfDay();

            return [$start, $start->addDay()];
        }

        return [$this->instant($date, $exemption->starts), $this->instant($date, $exemption->ends)];
    }

    private function coveredMinutes(Exemption $exemption, CarbonImmutable $date): int
    {
        if ($exemption->wholeDay()) {
            return 24 * 60;
        }

        [$starts, $ends] = $this->window($exemption, $date);

        return (int) round($starts->diffInMinutes($ends, false));
    }

    private function instant(CarbonImmutable $date, string $clock): CarbonImmutable
    {
        return $date->startOfDay()->addMinutes(Minutes::of($clock));
    }

    private function isOffTurn(Shift $shift): bool
    {
        return $shift->slots === [] && ! $shift->remote;
    }
}
