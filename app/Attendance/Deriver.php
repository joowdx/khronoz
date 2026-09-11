<?php

namespace App\Attendance;

use App\Enums\MissingSide;
use App\Enums\Premium;
use App\Enums\PunchKind;
use App\Enums\WorkdayStatus;
use App\Support\Intervals;
use Carbon\CarbonImmutable;

/**
 * The day's figures: status and the seven minute columns a workday
 * stores (06-attendance.md daily rules 1 to 10).
 *
 * Policy, not algebra. Presence and Intervals measure sets of minutes;
 * this class decides what those measures become on a holiday, a
 * suspension, a remote day, a half-filled slot, and an exemption.
 * Pure: Day and Matching in, a Derived out, no database.
 *
 * Assume lives here and never in the matcher (decision 64). A missing
 * side contributes no tardiness and no undertime (decision 71).
 *
 * @phpstan-type Range array{0: CarbonImmutable, 1: CarbonImmutable}
 * @phpstan-type Side array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}
 * @phpstan-type Punch array{slot: int, kind: string, expected_at: CarbonImmutable, timelog_id: ?string, actual_at: ?CarbonImmutable, deviation: ?int}
 */
final class Deriver
{
    public static function derive(
        Day $day,
        Matching $matching,
        MissingSide $missingSide,
        bool $premiumHours,
        bool $suspensionCharge,
        bool $precedingUnexcusedAbsence,
    ): Derived {
        $presence = self::presenceIntervals($matching->punches, $missingSide);
        $expected = self::expectedIntervals($matching->sides);
        $figures = Presence::of($presence, $expected, $day->nightFrom, $day->date);

        $credited = self::credited($day, $presence, $premiumHours);
        $excess = $day->travel ? 0 : max(0, $figures->excess() - $credited);
        [$tardy, $undertime] = self::lateness($matching, $day->excused);

        return new Derived(
            status: self::status($day, $matching),
            worked: self::worked($day, $figures->worked(), $suspensionCharge, $precedingUnexcusedAbsence),
            credited: $credited,
            tardy: $tardy,
            undertime: $undertime,
            excess: $excess,
            night: $figures->night(),
            nightExcess: $figures->nightExcess(),
        );
    }

    /**
     * @param  list<Punch>  $punches
     * @return list<Range>
     */
    private static function presenceIntervals(array $punches, MissingSide $missingSide): array
    {
        $presence = [];

        foreach (self::bySlot($punches) as $pair) {
            $in = $pair[PunchKind::In->value] ?? null;
            $out = $pair[PunchKind::Out->value] ?? null;
            $inAt = $in['actual_at'] ?? null;
            $outAt = $out['actual_at'] ?? null;

            if ($inAt !== null && $outAt !== null) {
                $start = $inAt;
                $end = $outAt;
            } elseif ($missingSide === MissingSide::Assume && $in !== null && $out !== null && ($inAt !== null xor $outAt !== null)) {
                $start = $inAt ?? $in['expected_at'];
                $end = $outAt ?? $out['expected_at'];
            } else {
                continue;
            }

            if ($start->lt($end)) {
                $presence[] = [$start, $end];
            }
        }

        return $presence;
    }

    /**
     * @param  list<Side>  $sides
     * @return list<Range>
     */
    private static function expectedIntervals(array $sides): array
    {
        $expected = [];

        foreach (self::bySlot($sides) as $pair) {
            $start = $pair[PunchKind::In->value]['at'];
            $end = $pair[PunchKind::Out->value]['at'];

            if ($start->lt($end)) {
                $expected[] = [$start, $end];
            }
        }

        return $expected;
    }

    /**
     * Rule 5's table. Premium before status because a regular holiday on
     * a rest day is off and regular, and worked follows the stronger cause.
     */
    private static function worked(
        Day $day,
        int $worked,
        bool $suspensionCharge,
        bool $precedingUnexcusedAbsence,
    ): int {
        if ($day->premium === Premium::Regular) {
            return $precedingUnexcusedAbsence ? 0 : self::required($day);
        }

        if ($day->premium === Premium::Special) {
            return 0;
        }

        if ($day->premium === Premium::Rest) {
            return 0;
        }

        if ($day->status === WorkdayStatus::Suspended) {
            return $suspensionCharge ? 0 : self::required($day);
        }

        if ($day->status === WorkdayStatus::Remote) {
            return self::required($day);
        }

        if ($day->shift === null) {
            return 0;
        }

        return $worked;
    }

    /**
     * @param  list<Range>  $presence
     */
    private static function credited(Day $day, array $presence, bool $premiumHours): int
    {
        if ($day->premium === null || ! $premiumHours || $day->sides !== []) {
            return 0;
        }

        return min(480, Intervals::minutes($presence));
    }

    /**
     * @param  list<Range>  $excused
     * @return array{0: int, 1: int}
     */
    private static function lateness(Matching $matching, array $excused): array
    {
        $sides = self::bySlot($matching->sides);
        $punches = self::bySlot($matching->punches);
        $firstAttended = self::firstAttendedSlot($punches);
        $tardySet = [];
        $undertimeSet = [];

        foreach ($sides as $slot => $pair) {
            $punched = $punches[$slot] ?? [];
            $inFilled = self::isFilled($punched, PunchKind::In);
            $outFilled = self::isFilled($punched, PunchKind::Out);
            $expectedIn = $pair[PunchKind::In->value]['at'];
            $expectedOut = $pair[PunchKind::Out->value]['at'];
            $grace = $pair[PunchKind::In->value]['grace'];

            if ($inFilled && $outFilled) {
                self::addTardy($tardySet, $expectedIn, $grace, $punched[PunchKind::In->value]['actual_at']);
                self::addUndertime($undertimeSet, $punched[PunchKind::Out->value]['actual_at'], $expectedOut);

                continue;
            }

            if (! $inFilled && ! $outFilled) {
                if ($firstAttended !== null && $expectedIn->lt($expectedOut)) {
                    if ($slot < $firstAttended) {
                        $tardySet[] = [$expectedIn, $expectedOut];
                    } else {
                        $undertimeSet[] = [$expectedIn, $expectedOut];
                    }
                }

                continue;
            }

            if ($inFilled) {
                self::addTardy($tardySet, $expectedIn, $grace, $punched[PunchKind::In->value]['actual_at']);
            }

            if ($outFilled) {
                self::addUndertime($undertimeSet, $punched[PunchKind::Out->value]['actual_at'], $expectedOut);
            }
        }

        return [
            Intervals::minutes(Intervals::subtract($tardySet, $excused)),
            Intervals::minutes(Intervals::subtract($undertimeSet, $excused)),
        ];
    }

    /**
     * @param  list<Range>  $set
     */
    private static function addTardy(array &$set, CarbonImmutable $expectedIn, int $grace, CarbonImmutable $actualIn): void
    {
        $start = $expectedIn->addMinutes($grace);

        if ($actualIn->gt($start)) {
            $set[] = [$start, $actualIn];
        }
    }

    /**
     * @param  list<Range>  $set
     */
    private static function addUndertime(array &$set, CarbonImmutable $actualOut, CarbonImmutable $expectedOut): void
    {
        if ($expectedOut->gt($actualOut)) {
            $set[] = [$actualOut, $expectedOut];
        }
    }

    /**
     * @param  array<int, array<string, Punch>>  $punches
     */
    private static function firstAttendedSlot(array $punches): ?int
    {
        $first = null;

        foreach ($punches as $slot => $pair) {
            if (self::isFilled($pair, PunchKind::In) || self::isFilled($pair, PunchKind::Out)) {
                $first = $first === null ? $slot : min($first, $slot);
            }
        }

        return $first;
    }

    /**
     * @param  array<string, Punch>  $pair
     */
    private static function isFilled(array $pair, PunchKind $kind): bool
    {
        return ($pair[$kind->value]['actual_at'] ?? null) !== null;
    }

    private static function status(Day $day, Matching $matching): WorkdayStatus
    {
        if ($day->status !== null) {
            return $day->status;
        }

        foreach ($matching->punches as $punch) {
            if ($punch['timelog_id'] !== null) {
                return WorkdayStatus::Present;
            }
        }

        return WorkdayStatus::Absent;
    }

    private static function required(Day $day): int
    {
        return $day->shift->required;
    }

    /**
     * @param  list<Side>|list<Punch>  $rows
     * @return array<int, array<string, Side|Punch>>
     */
    private static function bySlot(array $rows): array
    {
        $slots = [];

        foreach ($rows as $row) {
            $slots[$row['slot']][$row['kind']] = $row;
        }

        ksort($slots);

        return $slots;
    }
}
