<?php

namespace App\Attendance;

use App\Enums\MissingSide;
use App\Enums\PunchKind;
use App\Models\Punch;
use App\Support\Intervals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Daily rule 6's intersection: which of a workday's excess minutes an
 * `Overtime` authority actually covers.
 *
 * `workdays.excess` is a measure, not a set, so the intersection cannot
 * be taken on the row — it is taken here, on the punches, which carry
 * `expected_at` and `actual_at` for every transit precisely so a day is
 * reconstructible after the fact (decisions 33 and 54). A premium day's
 * punches have no expectation at all (decision 78), which is why the
 * whole of their presence is excess. This is the same set algebra
 * `Presence` runs at compute time, replayed from what was stored:
 *
 *     excess    = presence \ expected
 *     authorised = |(excess past its first `credited` minutes) ∩ authority|
 *
 * The `credited` offset is decision 51's: on a premium day the
 * expectation is empty, so `presence \ expected` is the whole of
 * presence and its first 480 minutes were paid as regular hours at a
 * premium rather than as excess. Dropping a *measure* would leave the
 * wrong minutes on the clock — an 09:00 authority against an 08:00
 * arrival must intersect nothing, not two hours.
 *
 * Read-time and derived, per rule 6 and decision 72: compensable
 * overtime is not a workday number and is never stored.
 *
 * @phpstan-type Range array{0: CarbonImmutable, 1: CarbonImmutable}
 */
final class Authorised
{
    /**
     * @param  Collection<int, Punch>  $punches
     * @param  list<Range>  $authorities
     */
    public static function minutes(
        Collection $punches,
        MissingSide $missingSide,
        int $credited,
        array $authorities,
    ): int {
        if ($authorities === []) {
            return 0;
        }

        $excess = Intervals::after(
            Intervals::subtract(self::presence($punches, $missingSide), self::expected($punches)),
            $credited,
        );

        return Intervals::minutes(Intervals::intersect($excess, $authorities));
    }

    /**
     * Mirrors `Deriver::presenceIntervals`, from the stored rows rather
     * than the matcher's. `assume` substitutes the expected instant for
     * the side no device recorded, which is where that policy has always
     * lived — the punch itself stays null on both columns (decision 64),
     * so the substitution has to be made again here or an `assume` agency
     * would authorise nothing on a half-recorded slot.
     *
     * @param  Collection<int, Punch>  $punches
     * @return list<Range>
     */
    private static function presence(Collection $punches, MissingSide $missingSide): array
    {
        $presence = [];

        foreach (self::bySlot($punches) as $pair) {
            $in = $pair[PunchKind::In->value] ?? null;
            $out = $pair[PunchKind::Out->value] ?? null;
            $inAt = self::at($in?->actual_at);
            $outAt = self::at($out?->actual_at);

            if ($inAt !== null && $outAt !== null) {
                $start = $inAt;
                $end = $outAt;
            } elseif ($missingSide === MissingSide::Assume && $in !== null && $out !== null && ($inAt !== null xor $outAt !== null)) {
                $start = $inAt ?? self::at($in->expected_at);
                $end = $outAt ?? self::at($out->expected_at);
            } else {
                continue;
            }

            if ($start !== null && $end !== null && $start->lt($end)) {
                $presence[] = [$start, $end];
            }
        }

        return $presence;
    }

    /**
     * Mirrors `Deriver::expectedIntervals`. The calendar's truncations —
     * a suspension cutting a slot short, a holiday emptying the day — are
     * already in `expected_at`, because the matcher was given the sides
     * the calendar had finished with.
     *
     * @param  Collection<int, Punch>  $punches
     * @return list<Range>
     */
    private static function expected(Collection $punches): array
    {
        $expected = [];

        foreach (self::bySlot($punches) as $pair) {
            $start = self::at(($pair[PunchKind::In->value] ?? null)?->expected_at);
            $end = self::at(($pair[PunchKind::Out->value] ?? null)?->expected_at);

            if ($start !== null && $end !== null && $start->lt($end)) {
                $expected[] = [$start, $end];
            }
        }

        return $expected;
    }

    /**
     * @param  Collection<int, Punch>  $punches
     * @return array<int, array<string, Punch>>
     */
    private static function bySlot(Collection $punches): array
    {
        $slots = [];

        foreach ($punches as $punch) {
            $slots[(int) $punch->slot][$punch->kind->value] = $punch;
        }

        return $slots;
    }

    /**
     * Naive local wall clock, the timezone kept, for the reason `Presence`
     * gives: converting would move a 22:00 Manila instant to 14:00 UTC.
     */
    private static function at(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value->format('Y-m-d H:i:s'));
    }
}
