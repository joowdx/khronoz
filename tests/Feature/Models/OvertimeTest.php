<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Employee;
use App\Models\Overtime;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * No agency_not_platform test and no such trigger: an overtime authority needs
 * an employee, and `employees` refuses the platform agency already.
 *
 * `date` never appears in the row helper below — it is generated, and Postgres
 * refuses any supplied value for a generated column. That refusal has its own
 * test.
 */
class OvertimeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function overtimeRow(Overtime $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'employee_id' => $like->employee_id,
            'starts' => '2027-03-01 17:00:00',
            'ends' => '2027-03-01 20:00:00',
            'purpose' => 'Inventory count',
            'mode' => 'pay',
            'reference' => 'Office Order No. 4',
            'user_id' => $like->user_id,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_overtime_needs_an_agency(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['agency_id' => null])
        ));
    }

    public function test_employee_is_required(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['employee_id' => null])
        ));
    }

    public function test_starts_is_required(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses(
            '23502',
            fn () => DB::table('overtimes')->insert($this->overtimeRow($overtime, ['starts' => null])),
            'column "starts"',
        );
    }

    public function test_ends_is_required(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['ends' => null])
        ));
    }

    public function test_purpose_is_required(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['purpose' => null])
        ));
    }

    public function test_mode_is_required(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['mode' => null])
        ));
    }

    public function test_approving_user_is_required(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['user_id' => null])
        ));
    }

    public function test_mode_must_be_pay_or_cto(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['mode' => 'leave'])
        ));
    }

    public function test_an_authority_cannot_end_when_it_starts(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['starts' => '2027-03-01 17:00:00', 'ends' => '2027-03-01 17:00:00'])
        ));
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'overtimes_id_agency_id_unique'"));
    }

    public function test_employee_must_share_the_overtimes_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Overtime::factory()->create(['employee_id' => $employee->id]));
    }

    public function test_employee_with_an_authority_cannot_be_hard_deleted(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $overtime->employee_id)->delete());
    }

    public function test_approving_user_must_exist(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['user_id' => (string) Str::ulid()])
        ));
    }

    public function test_user_who_approved_an_authority_cannot_be_deleted(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('users')->where('id', $overtime->user_id)->delete());
    }

    public function test_the_approving_user_may_belong_to_another_agency(): void
    {
        $employee = Employee::factory()->create();
        $superuser = User::factory()->platform()->create();

        $overtime = Overtime::factory()->create([
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
            'user_id' => $superuser->id,
        ]);

        $this->assertSame($this->platform()->id, $overtime->user->agency_id);
    }

    public function test_authorities_for_one_employee_cannot_overlap(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23P01', fn () => DB::table('overtimes')->insert($this->overtimeRow($overtime, [
            'starts' => $overtime->starts->copy()->addHour()->toDateTimeString(),
            'ends' => $overtime->ends->copy()->addHour()->toDateTimeString(),
        ])));
    }

    public function test_two_authorities_may_meet_at_an_instant(): void
    {
        $first = Overtime::factory()->create(['starts' => '2026-09-15 18:00:00', 'ends' => '2026-09-15 20:00:00']);

        $second = Overtime::factory()->create([
            'agency_id' => $first->agency_id,
            'employee_id' => $first->employee_id,
            'user_id' => $first->user_id,
            'starts' => '2026-09-15 20:00:00',
            'ends' => '2026-09-15 22:00:00',
        ]);

        $this->assertDatabaseHas('overtimes', ['id' => $second->id]);
    }

    public function test_two_employees_may_be_authorised_at_the_same_time(): void
    {
        $first = Overtime::factory()->create();
        $colleague = Employee::factory()->create(['agency_id' => $first->agency_id]);

        $second = Overtime::factory()->create([
            'agency_id' => $first->agency_id,
            'employee_id' => $colleague->id,
            'user_id' => $first->user_id,
            'starts' => $first->starts->toDateTimeString(),
            'ends' => $first->ends->toDateTimeString(),
        ]);

        $this->assertDatabaseHas('overtimes', ['id' => $second->id]);
        $this->assertNotSame($first->employee_id, $second->employee_id);
    }

    public function test_the_date_is_generated_from_the_start_and_follows_it(): void
    {
        $overtime = Overtime::factory()->create(['starts' => '2026-09-15 17:00:00', 'ends' => '2026-09-15 20:00:00']);

        $this->assertSame('2026-09-15', $overtime->date->toDateString());

        DB::table('overtimes')->where('id', $overtime->id)->update([
            'starts' => '2026-10-20 17:00:00',
            'ends' => '2026-10-20 20:00:00',
        ]);

        $this->assertSame('2026-10-20', $overtime->fresh()->date->toDateString());
    }

    public function test_an_overnight_authority_is_one_row_dated_by_its_start(): void
    {
        $overtime = Overtime::factory()->overnight()->create();

        $this->assertSame('2026-09-15', $overtime->date->toDateString());
        $this->assertSame('2026-09-16', $overtime->ends->toDateString());
        $this->assertSame(240, $overtime->minutes());
    }

    public function test_the_generated_date_is_stored_and_not_virtual(): void
    {
        $column = DB::selectOne("select attgenerated from pg_attribute where attrelid = 'overtimes'::regclass and attname = 'date'");

        $this->assertSame('s', $column->attgenerated, "expected STORED ('s'), got ".var_export($column->attgenerated, true));
    }

    public function test_the_date_cannot_be_written(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('428C9', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['date' => '2027-01-01'])
        ));
    }

    public function test_an_overnight_authority_is_found_by_its_window_not_its_date(): void
    {
        $employee = Employee::factory()->create();
        $this->withTenant(Agency::findOrFail($employee->agency_id));
        Overtime::factory()->overnight()->create([
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
        ]);

        $sixteenth = CarbonImmutable::parse('2026-09-16');

        $this->assertSame(0, Overtime::startingOn($sixteenth)->count(), 'it is filed on the 15th');
        $this->assertSame(
            1,
            Overtime::overlapping($sixteenth->startOfDay(), $sixteenth->endOfDay())->count(),
            'but it authorises minutes on the 16th',
        );
        $this->assertSame(1, Overtime::startingOn($sixteenth->subDay())->count());
    }

    public function test_the_recording_user_cannot_belong_to_a_third_agency(): void
    {
        $overtime = Overtime::factory()->create();
        $stranger = User::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['user_id' => $stranger->id])
        ));
    }
}
