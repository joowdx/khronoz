<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Terminal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function enrollmentRow(Enrollment $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'employee_id' => $like->employee_id,
            'terminal_id' => $like->terminal_id,
            'uid' => (string) fake()->unique()->numberBetween(10000, 99999),
            'privilege' => 'user',
            'starts' => '2027-01-01',
            'ends' => null,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_enrollment_needs_an_agency(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, ['agency_id' => null])
        ));
    }

    public function test_uid_is_required(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, ['uid' => null])
        ));
    }

    public function test_privilege_is_required(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, ['privilege' => null])
        ));
    }

    public function test_starts_is_required(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, ['starts' => null])
        ));
    }

    public function test_privilege_must_be_a_known_value(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, ['privilege' => 'root'])
        ));
    }

    public function test_an_enrollment_cannot_end_before_it_starts(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, ['starts' => '2027-03-01', 'ends' => '2027-02-28'])
        ));
    }

    public function test_an_enrollment_may_begin_and_end_on_one_day(): void
    {
        $enrollment = Enrollment::factory()->create();
        $colleague = Employee::factory()->create(['agency_id' => $enrollment->agency_id]);

        DB::table('enrollments')->insert($this->enrollmentRow($enrollment, [
            'employee_id' => $colleague->id,
            'starts' => '2027-03-01',
            'ends' => '2027-03-01',
        ]));

        $this->assertDatabaseHas('enrollments', ['starts' => '2027-03-01', 'ends' => '2027-03-01']);
    }

    public function test_employee_must_share_the_enrollments_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Enrollment::factory()->create(['employee_id' => $employee->id]));
    }

    public function test_employee_with_an_enrollment_cannot_be_hard_deleted(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $enrollment->employee_id)->delete());
    }

    public function test_terminal_must_share_the_enrollments_agency(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Enrollment::factory()->create(['terminal_id' => $terminal->id]));
    }

    public function test_terminal_with_an_enrollment_cannot_be_deleted(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('terminals')->where('id', $enrollment->terminal_id)->delete());
    }

    public function test_one_device_user_id_cannot_be_two_people_at_once(): void
    {
        $enrollment = Enrollment::factory()->create();
        $colleague = Employee::factory()->create(['agency_id' => $enrollment->agency_id]);

        $this->assertDatabaseRefuses('23P01', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, [
                'employee_id' => $colleague->id,
                'uid' => $enrollment->uid,
                'starts' => $enrollment->starts->addMonths(6)->toDateString(),
            ])
        ));
    }

    public function test_one_person_cannot_hold_two_device_user_ids_on_one_terminal_at_once(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23P01', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, [
                'uid' => $enrollment->uid.'9',
                'starts' => $enrollment->starts->toDateString(),
            ])
        ));
    }

    public function test_a_device_user_id_may_be_reissued_once_its_enrollment_has_ended(): void
    {
        $leaver = Enrollment::factory()->closed('+6 months')->create();
        $this->withTenant(Agency::findOrFail($leaver->agency_id));

        $terminal = Terminal::findOrFail($leaver->terminal_id);
        $joiner = Employee::factory()->create(['agency_id' => $leaver->agency_id]);

        $successor = Enrollment::factory()->on($terminal)->forEmployee($joiner)->create([
            'uid' => $leaver->uid,
            'starts' => $leaver->ends->addDay(),
        ]);

        $this->assertSame(
            [$leaver->employee_id],
            Enrollment::where('uid', $leaver->uid)->covering($leaver->starts)->pluck('employee_id')->all(),
        );
        $this->assertSame(
            [$successor->employee_id],
            Enrollment::where('uid', $leaver->uid)->covering($successor->starts)->pluck('employee_id')->all(),
        );
    }

    public function test_one_person_may_hold_a_different_uid_on_each_terminal(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->withTenant(Agency::findOrFail($enrollment->agency_id));

        $employee = Employee::findOrFail($enrollment->employee_id);
        $annex = Terminal::factory()->create(['agency_id' => $enrollment->agency_id]);

        $second = Enrollment::factory()->on($annex)->forEmployee($employee)->create([
            'uid' => $enrollment->uid.'9',
            'starts' => $enrollment->starts,
        ]);

        $this->assertNotSame($enrollment->terminal_id, $second->terminal_id);
        $this->assertSame($enrollment->employee_id, $second->employee_id);
    }

    public function test_two_terminals_may_each_have_the_same_uid_for_different_people(): void
    {
        $enrollment = Enrollment::factory()->create();
        $annex = Terminal::factory()->create(['agency_id' => $enrollment->agency_id]);
        $colleague = Employee::factory()->create(['agency_id' => $enrollment->agency_id]);

        $second = Enrollment::factory()->on($annex)->forEmployee($colleague)->create([
            'uid' => $enrollment->uid,
            'starts' => $enrollment->starts,
        ]);

        $this->assertSame($enrollment->uid, $second->uid);
        $this->assertNotSame($enrollment->employee_id, $second->employee_id);
    }

    public function test_the_resolution_key_is_declared_unique(): void
    {
        $this->assertSame(
            'UNIQUE (id, employee_id, terminal_id, uid)',
            DB::selectOne("select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'enrollments_resolution_key'")->def,
        );
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'enrollments_id_agency_id_unique'"));
    }
}
