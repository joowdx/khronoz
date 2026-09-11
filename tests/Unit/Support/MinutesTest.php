<?php

namespace Tests\Unit\Support;

use App\Support\Minutes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Slot times are `HH:MM` with hours past 24 rolling into later days
 * (04-scheduling.md, slot shape). That roll-over is why this helper exists
 * instead of a clock library that wraps at midnight: `"30:00"` is 06:00 the
 * next day and `"56:00"` is 08:00 two days on, and RestPeriod already
 * measured those gaps with a private copy of this conversion.
 */
class MinutesTest extends TestCase
{
    /** @return array<string, array{0: string, 1: int}> */
    public static function times(): array
    {
        return [
            'midnight' => ['00:00', 0],
            'one minute' => ['00:01', 1],
            'standard in' => ['08:00', 480],
            'standard out' => ['17:00', 1020],
            'civil-service night from' => ['18:00', 1080],
            'past midnight' => ['30:00', 1800],
            '48-hour duty out' => ['56:00', 3360],
        ];
    }

    #[DataProvider('times')]
    public function test_converts_hh_mm_to_minutes_past_midnight(string $time, int $minutes): void
    {
        $this->assertSame($minutes, Minutes::of($time));
    }
}
