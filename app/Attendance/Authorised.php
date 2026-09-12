<?php

namespace App\Attendance;

use App\Enums\MissingSide;
use App\Enums\PunchKind;
use App\Models\Punch;
use App\Support\Intervals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
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

    private static function at(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value->format('Y-m-d H:i:s'));
    }
}
