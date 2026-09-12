<?php

namespace App\Attendance;

use App\Enums\CadenceKind;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class CadenceRange
{
    /** @return array{CarbonImmutable, CarbonImmutable} */
    public static function containing(CadenceKind $kind, CarbonInterface $date, ?CarbonInterface $anchor = null, array $rules = []): array
    {
        $day = CarbonImmutable::parse($date->format('Y-m-d'), 'Asia/Manila');
        if ($kind === CadenceKind::Weekly || $kind === CadenceKind::Fortnightly) {
            if ($anchor === null) {
                throw new InvalidArgumentException('A weekly or fortnightly cadence requires an anchor.');
            }
            $origin = CarbonImmutable::parse($anchor->format('Y-m-d'), 'Asia/Manila');
            $length = $kind === CadenceKind::Weekly ? 7 : 14;
            $offset = (int) floor($origin->diffInDays($day, false) / $length);
            $from = $origin->addDays($offset * $length);

            return [$from, $from->addDays($length - 1)];
        }

        $starts = $rules['starts'] ?? match ($kind) {
            CadenceKind::Semimonthly => [1, 16],
            CadenceKind::Monthly => [1],
            default => [],
        };

        $expected = $kind === CadenceKind::Semimonthly ? 2 : 1;

        if (! is_array($starts)
            || count($starts) !== $expected
            || array_values($starts) !== array_values(array_unique($starts))
            || array_values($starts) !== collect($starts)->sort()->values()->all()
            || collect($starts)->contains(fn (mixed $start): bool => ! is_int($start) || $start < 1 || $start > 28)) {
            throw new InvalidArgumentException("A {$kind->value} cadence requires {$expected} ordered start day(s) between 1 and 28.");
        }

        $boundaries = collect([-1, 0, 1])
            ->flatMap(function (int $offset) use ($day, $starts): array {
                $month = $day->startOfMonth()->addMonthsNoOverflow($offset);

                return collect($starts)
                    ->map(fn (int $start): CarbonImmutable => $month->setDay($start))
                    ->all();
            })
            ->sortBy(fn (CarbonImmutable $boundary): int => $boundary->getTimestamp())
            ->values();

        /** @var CarbonImmutable $from */
        $from = $boundaries->last(fn (CarbonImmutable $boundary): bool => $boundary->lte($day));
        /** @var CarbonImmutable $next */
        $next = $boundaries->first(fn (CarbonImmutable $boundary): bool => $boundary->gt($day));

        return [$from, $next->subDay()];
    }
}
