<?php

namespace Tests\Unit\Enums;

use App\Enums\HolidayType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Decision 49: `local` is a rate of `special`, and HolidayType says so in a
 * method rather than the deriver saying it in a match. Swept across every
 * case rather than spot-checked, because the property is defined by a single
 * exception and a spot check on `Working` alone would pass against
 * `return true`.
 */
class HolidayTypeTest extends TestCase
{
    /** @return array<string, array{0: HolidayType, 1: bool}> */
    public static function types(): array
    {
        return [
            'regular' => [HolidayType::Regular, false],
            'special' => [HolidayType::Special, false],
            'working' => [HolidayType::Working, true],
            'local' => [HolidayType::Local, false],
        ];
    }

    /**
     * True only for `working` (decision 49, 05-calendar.md rule 1). A local
     * holiday is one declared by ordinance and behaves as special: no work
     * expected, worked time premium-rated at the special rate. A deriver
     * written from 05-calendar.md rule 1 before this method existed either
     * raised on the fourth value or silently ran an ordinary day.
     */
    #[DataProvider('types')]
    public function test_only_a_working_holiday_keeps_the_shift(HolidayType $type, bool $expectsWork): void
    {
        $this->assertSame($expectsWork, $type->expectsWork(), $type->value);
    }

    /** Every case is covered by the table above — no case may be added without a row. */
    public function test_every_case_is_swept(): void
    {
        $this->assertSame(
            array_map(fn (HolidayType $t): string => $t->value, HolidayType::cases()),
            array_keys(self::types()),
        );
    }
}
