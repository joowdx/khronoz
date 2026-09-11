<?php

namespace Tests\Unit\Enums;

use App\Enums\EnrollmentPrivilege;
use App\Enums\ExemptionType;
use App\Enums\HolidayType;
use App\Enums\OvertimeMode;
use App\Enums\Sex;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Enums\TerminalKind;
use App\Enums\TerminalProtocol;
use App\Enums\TimelogSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `.ai/rules/enums.md`: a backed enum exists "one per varchar column + CHECK
 * (... IN (...)) constraint". Nothing enforced that pairing, so an enum and
 * its CHECK could drift — a case added to the enum and not the DDL writes a
 * value the database refuses at runtime, and a value added to the DDL and not
 * the enum comes back as a cast error on read.
 *
 * This parses the CHECK out of the migration and compares it to the enum's
 * own values, the same technique PermissionContractTest uses on the
 * TypeScript union. Add an enum mirroring a CHECK, add a row here.
 *
 * Sets, not lists: the order of an `IN (...)` says nothing, and neither does
 * the declaration order of the cases.
 */
class EnumCheckContractTest extends TestCase
{
    /** @return array<string, array{0: class-string, 1: string, 2: string}> */
    public static function enums(): array
    {
        return [
            'employees.sex' => [Sex::class, '0001_01_01_000009_create_employees_table.php', 'employees_sex_valid'],
            'holidays.type' => [HolidayType::class, '0001_01_01_000019_create_holidays_table.php', 'holidays_type_valid'],
            'exemptions.type' => [ExemptionType::class, '0001_01_01_000021_create_exemptions_table.php', 'exemptions_type_valid'],
            'overtimes.mode' => [OvertimeMode::class, '0001_01_01_000022_create_overtimes_table.php', 'overtimes_mode_valid'],
            'enrollments.privilege' => [EnrollmentPrivilege::class, '0001_01_01_000025_create_enrollments_table.php', 'enrollments_privilege_valid'],
            'syncs.trigger' => [SyncTrigger::class, '0001_01_01_000026_create_syncs_table.php', 'syncs_trigger_valid'],
            'syncs.status' => [SyncStatus::class, '0001_01_01_000026_create_syncs_table.php', 'syncs_status_valid'],
            'terminals.kind' => [TerminalKind::class, '0001_01_01_000024_create_terminals_table.php', 'terminals_kind_valid'],
            'terminals.protocol' => [TerminalProtocol::class, '0001_01_01_000024_create_terminals_table.php', 'terminals_protocol_valid'],
            'timelogs.source' => [TimelogSource::class, '0001_01_01_000027_create_timelogs_table.php', 'timelogs_source_valid'],
        ];
    }

    #[DataProvider('enums')]
    public function test_the_enum_and_its_check_constraint_hold_the_same_values(
        string $enum,
        string $migration,
        string $constraint,
    ): void {
        $ddl = file_get_contents(__DIR__.'/../../../database/migrations/'.$migration);

        $this->assertNotFalse($ddl, "{$migration} is unreadable");
        $this->assertMatchesRegularExpression(
            '/ADD CONSTRAINT '.preg_quote($constraint, '/').' CHECK \(\s*\w+ IN \(([^)]+)\)/',
            $ddl,
            "{$constraint} is not an `IN (...)` CHECK in {$migration}",
        );

        preg_match('/ADD CONSTRAINT '.preg_quote($constraint, '/').' CHECK \(\s*\w+ IN \(([^)]+)\)/', $ddl, $found);
        preg_match_all("/'([^']+)'/", $found[1], $quoted);

        $declared = $quoted[1];
        sort($declared);

        $cases = array_column($enum::cases(), 'value');
        sort($cases);

        $this->assertSame($declared, $cases, "{$enum} and {$constraint} have drifted");
    }

    /** Every case must answer label(), and no two may answer the same thing. */
    #[DataProvider('enums')]
    public function test_every_case_has_a_distinct_label(string $enum): void
    {
        $labels = array_map(fn ($case) => $case->label(), $enum::cases());

        $this->assertNotContains('', $labels);
        $this->assertSame($labels, array_unique($labels), "{$enum} has two cases with one label");
    }
}
