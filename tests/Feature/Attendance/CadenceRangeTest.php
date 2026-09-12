<?php

namespace Tests\Feature\Attendance;

use App\Attendance\CadenceRange;
use App\Enums\CadenceKind;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CadenceRangeTest extends TestCase
{
    #[DataProvider('ranges')]
    public function test_resolves_inclusive_ranges(CadenceKind $kind, string $day, ?string $anchor, array $rules, string $from, string $to): void
    {
        [$starts, $ends] = CadenceRange::containing($kind, CarbonImmutable::parse($day), $anchor === null ? null : CarbonImmutable::parse($anchor), $rules);

        $this->assertSame($from, $starts->toDateString());
        $this->assertSame($to, $ends->toDateString());
    }

    public static function ranges(): array
    {
        return [
            'weekly anchor' => [CadenceKind::Weekly, '2026-09-12', '2026-09-07', [], '2026-09-07', '2026-09-13'],
            'weekly before anchor' => [CadenceKind::Weekly, '2026-09-06', '2026-09-07', [], '2026-08-31', '2026-09-06'],
            'fortnight crosses year' => [CadenceKind::Fortnightly, '2026-01-01', '2026-01-05', [], '2025-12-22', '2026-01-04'],
            'fortnight anchor day' => [CadenceKind::Fortnightly, '2026-01-05', '2026-01-05', [], '2026-01-05', '2026-01-18'],
            'semimonth first range inclusive' => [CadenceKind::Semimonthly, '2024-02-15', null, [], '2024-02-01', '2024-02-15'],
            'semimonth leap end' => [CadenceKind::Semimonthly, '2024-02-16', null, [], '2024-02-16', '2024-02-29'],
            'custom semimonth starts' => [CadenceKind::Semimonthly, '2026-02-11', null, ['starts' => [5, 20]], '2026-02-05', '2026-02-19'],
            'monthly leap year' => [CadenceKind::Monthly, '2024-02-29', null, [], '2024-02-01', '2024-02-29'],
            'custom monthly crosses month' => [CadenceKind::Monthly, '2026-02-02', null, ['starts' => [5]], '2026-01-05', '2026-02-04'],
        ];
    }

    public function test_rejects_fortnight_without_anchor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CadenceRange::containing(CadenceKind::Fortnightly, CarbonImmutable::parse('2026-01-01'));
    }

    public function test_rejects_invalid_monthly_start_days(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CadenceRange::containing(CadenceKind::Monthly, CarbonImmutable::parse('2026-02-01'), rules: ['starts' => [29]]);
    }
}
