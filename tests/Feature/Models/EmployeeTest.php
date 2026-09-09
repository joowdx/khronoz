<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    /** Baseline row for a raw insert, every NOT NULL column set explicitly. */
    private function employeeRow(string $agencyId, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'number' => fake()->unique()->numerify('EMP#####'),
            'first_name' => 'X',
            'last_name' => 'X',
            'sex' => null,
            'tags' => '[]',
            'exempt' => false,
            'hired_at' => '2020-01-01',
            'separated_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    public function test_employee_needs_an_agency(): void
    {
        $this->assertDatabaseRefuses('23502', fn () => Employee::factory()->create(['agency_id' => null]));
    }

    /** employees_agency_id_foreign, insert side. employees carries no other FK, so this is cleanly isolated. */
    public function test_agency_id_must_reference_an_existing_agency(): void
    {
        $this->assertDatabaseRefuses('23503', fn () => DB::table('employees')->insert(
            $this->employeeRow((string) Str::ulid()) // no such agency
        ));
    }

    /** employees_agency_id_foreign, delete side. Non-platform agency — see AgencyTest's documented trap. */
    public function test_agency_with_employees_cannot_be_deleted(): void
    {
        $agency = Agency::factory()->create();
        Employee::factory()->create(['agency_id' => $agency->id]);

        $this->assertDatabaseRefuses('23001', fn () => DB::table('agencies')->where('id', $agency->id)->delete());
    }

    public function test_id_and_agency_id_pair_is_unique(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('employees')->insert(
            $this->employeeRow($employee->agency_id, ['id' => $employee->id])
        ));

        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'employees_id_agency_id_unique'"));
    }

    public function test_number_is_unique_per_agency(): void
    {
        $agency = Agency::factory()->create();
        Employee::factory()->create(['agency_id' => $agency->id, 'number' => 'EMP1']);

        $this->assertDatabaseRefuses('23505', fn () => Employee::factory()->create(['agency_id' => $agency->id, 'number' => 'EMP1']));

        // Same number, a different agency: accepted.
        $elsewhere = Employee::factory()->create(['number' => 'EMP1']);
        $this->assertDatabaseHas('employees', ['id' => $elsewhere->id, 'number' => 'EMP1']);
    }

    public function test_separation_cannot_precede_hiring(): void
    {
        $agency = Agency::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('employees')->insert(
            $this->employeeRow($agency->id, ['hired_at' => '2026-03-01', 'separated_at' => '2026-02-28'])
        ));
    }

    public function test_sex_must_be_a_recognized_value(): void
    {
        $agency = Agency::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('employees')->insert(
            $this->employeeRow($agency->id, ['sex' => 'x'])
        ));
    }

    /**
     * employees_tags_valid. Every shape here is 23514, never 22023 — the
     * bound CHECK's own jsonb_typeof guard (Task 2) keeps a non-array value
     * like '{}' from surfacing jsonb_array_length()'s raw error instead of
     * the shape violation this constraint owns.
     */
    public function test_tags_must_be_a_set_of_distinct_non_empty_strings(): void
    {
        $agency = Agency::factory()->create();

        foreach (['{}', '{"a":1}', '"x"', '["a","a"]', '[""]', '[1]'] as $shape) {
            $this->assertDatabaseRefuses('23514', fn () => DB::table('employees')->insert(
                $this->employeeRow($agency->id, ['tags' => $shape])
            ));
        }
    }

    public function test_tags_are_bounded_to_twenty(): void
    {
        $agency = Agency::factory()->create();
        $tags = fn (int $n) => json_encode(array_map(fn (int $i) => "tag{$i}", range(1, $n)));

        $this->assertDatabaseRefuses('23514', fn () => DB::table('employees')->insert(
            $this->employeeRow($agency->id, ['tags' => $tags(21)])
        ));

        $accepted = Employee::factory()->create([
            'agency_id' => $agency->id,
            'tags' => array_map(fn (int $i) => "tag{$i}", range(1, 20)),
        ]);
        $this->assertCount(20, $accepted->fresh()->tags);
    }

    /** agency_not_platform on employees: neither an INSERT under the platform agency nor an UPDATE into it is allowed. */
    public function test_an_employee_cannot_belong_to_the_platform_agency(): void
    {
        $platform = $this->platform();

        $this->assertDatabaseRefuses('P0001', fn () => Employee::factory()->create(['agency_id' => $platform->id]));

        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('employees')->where('id', $employee->id)->update(['agency_id' => $platform->id]));
    }

    /**
     * UNIQUE (agency_id, number) is not partial (R7): a soft-deleted
     * employee's number stays reserved forever. $employee->delete() here is
     * deliberately the soft delete — the point is the row still occupies the
     * unique index, not that a hard delete would be refused.
     */
    public function test_a_soft_deleted_employees_number_stays_reserved(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'number' => 'EMP1']);
        $employee->delete();

        $this->assertDatabaseRefuses('23505', fn () => Employee::factory()->create(['agency_id' => $agency->id, 'number' => 'EMP1']));
    }

    /** hired_at is NOT NULL: a null value makes employees_separation_after_hire evaluate to NULL and pass, which would hide a missing date. */
    public function test_hired_at_is_required(): void
    {
        $agency = Agency::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('employees')->insert(
            $this->employeeRow($agency->id, ['hired_at' => null])
        ));
    }

    /** exempt is NOT NULL: it has a database default of false, which an omitted column would mask — this inserts an explicit null instead. */
    public function test_exempt_is_required(): void
    {
        $agency = Agency::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('employees')->insert(
            $this->employeeRow($agency->id, ['exempt' => null])
        ));
    }

    /**
     * tags is NOT NULL: it has a database default of '[]', which an omitted
     * column would mask — this inserts an explicit null instead. With the
     * column nullable, string_set_valid(NULL) returns NULL, so both tags
     * CHECKs would pass and a tagless employee would be indistinguishable
     * from a broken one.
     */
    public function test_tags_is_required(): void
    {
        $agency = Agency::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('employees')->insert(
            $this->employeeRow($agency->id, ['tags' => null])
        ));
    }
}
