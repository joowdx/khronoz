<?php

namespace Tests\Unit\Enums;

use App\Enums\ExemptionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExemptionTypeTest extends TestCase
{
    /** @return array<string, array{0: ExemptionType, 1: bool, 2: bool}> */
    public static function types(): array
    {
        return [
            //                          excuses  suppresses excess
            'leave' => [ExemptionType::Leave, true, false],
            'business' => [ExemptionType::Business, true, false],
            'travel' => [ExemptionType::Travel, true, true],
            'cto' => [ExemptionType::Cto, true, false],
            'pass' => [ExemptionType::Pass, true, false],
            'personal' => [ExemptionType::Personal, false, false],
            'emergency' => [ExemptionType::Emergency, true, false],
        ];
    }

    #[DataProvider('types')]
    public function test_only_a_personal_slip_excuses_nothing(ExemptionType $type, bool $excuses): void
    {
        $this->assertSame($excuses, $type->excuses(), $type->value);
    }

    #[DataProvider('types')]
    public function test_only_official_travel_suppresses_excess(ExemptionType $type, bool $excuses, bool $suppresses): void
    {
        $this->assertSame($suppresses, $type->suppressesExcess(), $type->value);
    }

    public function test_every_case_is_swept(): void
    {
        $this->assertSame(
            array_map(fn (ExemptionType $t): string => $t->value, ExemptionType::cases()),
            array_keys(self::types()),
        );
    }
}
