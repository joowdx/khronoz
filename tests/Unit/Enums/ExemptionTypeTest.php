<?php

namespace Tests\Unit\Enums;

use App\Enums\ExemptionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two behavioural properties of decision 19 and 05-calendar.md rule 7,
 * swept across every case rather than spot-checked, because each is defined by
 * a single exception and a spot check on the exception alone would pass
 * against `return false`.
 *
 * They are deliberately **separate** properties. Folding suppressesExcess()
 * into excuses() would either make travel excuse nothing or make every leave
 * suppress overtime.
 */
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

    /**
     * False only for `personal`. A personal locator or OB slip is recorded and
     * printed but leaves the day's tardiness, undertime and absence exactly as
     * the punches make them; the minutes are charged to leave (Omnibus Rules
     * on Leave §34).
     */
    #[DataProvider('types')]
    public function test_only_a_personal_slip_excuses_nothing(ExemptionType $type, bool $excuses): void
    {
        $this->assertSame($excuses, $type->excuses(), $type->value);
    }

    /**
     * True only for `travel` (CSC-DBM JC 2 s. 2015 §7.3): a day spent
     * travelling on the office's business does not generate overtime from the
     * hours the journey happens to occupy. Note that travel both excuses *and*
     * suppresses — it is the one case where the two properties differ, which
     * is the whole reason there are two.
     */
    #[DataProvider('types')]
    public function test_only_official_travel_suppresses_excess(ExemptionType $type, bool $excuses, bool $suppresses): void
    {
        $this->assertSame($suppresses, $type->suppressesExcess(), $type->value);
    }

    /** Every case is covered by the table above — no case may be added without a row. */
    public function test_every_case_is_swept(): void
    {
        $this->assertSame(
            array_map(fn (ExemptionType $t): string => $t->value, ExemptionType::cases()),
            array_keys(self::types()),
        );
    }
}
