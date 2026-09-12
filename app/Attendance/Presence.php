<?php

namespace App\Attendance;

use App\Support\Intervals;
use App\Support\Minutes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * @phpstan-type Range array{0: CarbonImmutable, 1: CarbonImmutable}
 */
final class Presence
{
    private function __construct(
        private int $worked,
        private int $excess,
        private int $night,
        private int $nightExcess,
    ) {}

    /**
     * @param  list<Range>  $presence
     * @param  list<Range>  $expected
     */
    public static function of(array $presence, array $expected, string $nightFrom, CarbonInterface $date): self
    {
        $presence = Intervals::union($presence);
        $expected = Intervals::union($expected);
        $nightly = self::nightly($nightFrom, $date);

        $worked = Intervals::intersect($presence, $expected);

        return new self(
            Intervals::minutes($worked),
            Intervals::minutes(Intervals::subtract($presence, $expected)),
            Intervals::minutes(Intervals::intersect($worked, $nightly)),
            Intervals::minutes(Intervals::subtract(Intervals::intersect($presence, $nightly), $expected)),
        );
    }

    public function worked(): int
    {
        return $this->worked;
    }

    public function excess(): int
    {
        return $this->excess;
    }

    public function night(): int
    {
        return $this->night;
    }

    public function nightExcess(): int
    {
        return $this->nightExcess;
    }

    /**
     * @return list<Range>
     */
    private static function nightly(string $nightFrom, CarbonInterface $date): array
    {
        $origin = CarbonImmutable::instance($date)->startOfDay();
        $from = Minutes::of($nightFrom);
        $ranges = [];

        for ($offset = -1; $offset <= 3; $offset++) {
            $day = $origin->addDays($offset);
            $ranges[] = [
                $day->addMinutes($from),
                $day->addDay()->addHours(6),
            ];
        }

        return Intervals::union($ranges);
    }
}
