<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\RemoveEmployee;
use App\Enums\Permission;
use App\Enums\WorkdayStatus;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Punch;
use App\Models\Shift;
use App\Models\Workday;
use App\Models\Workgroup;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkdayControllerTest extends TestCase
{
    public function test_viewing_requires_ledgers_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewCalendar);

        $this->get(route('workdays.index'))->assertForbidden();
    }

    public function test_the_index_lists_this_agencys_workdays_only(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $mine = Workday::factory()->create(['agency_id' => $agency->id, 'date' => '2026-09-15', 'worked' => 480]);
        Workday::factory()->create(['date' => '2026-09-15']);

        $this->get(route('workdays.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page
                ->component('workdays/index')
                ->has('workdays', 1)
                ->where('workdays.0.id', $mine->id)
                ->where('workdays.0.date', '2026-09-15')
                ->where('workdays.0.shift_name', 'Standard')
                ->where('workdays.0.worked', 480)
        );
    }

    public function test_the_shift_name_is_the_frozen_snapshot_not_the_live_row(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $shift = Shift::factory()->create([
            'agency_id' => $agency->id,
            'name' => 'Standard',
        ]);
        Workday::factory()->create([
            'agency_id' => $agency->id,
            'shift_id' => $shift->id,
            'date' => '2026-09-15',
        ]);

        $this->withTenant($agency);
        $shift->update(['name' => 'Graveyard']);

        $this->get(route('workdays.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page->where('workdays.0.shift_name', 'Standard')
        );
    }

    public function test_the_index_exposes_frozen_holiday_names_and_types(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $workday = Workday::factory()->create([
            'agency_id' => $agency->id,
            'date' => '2026-09-15',
        ]);
        $snapshot = $workday->shift;
        $snapshot['holidays'] = [[
            'id' => '01K5HOLIDAY0000000000000000',
            'name' => 'City Foundation Day',
            'type' => 'local',
        ]];
        $workday->update(['shift' => $snapshot]);

        $this->get(route('workdays.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page
                ->where('workdays.0.holidays.0.name', 'City Foundation Day')
                ->where('workdays.0.holidays.0.type.value', 'local')
                ->where('workdays.0.holidays.0.type.label', 'Local holiday')
        );
    }

    public function test_the_index_is_scoped_to_the_requested_month(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $september = Workday::factory()->create(['agency_id' => $agency->id, 'date' => '2026-09-15']);
        Workday::factory()->create(['agency_id' => $agency->id, 'date' => '2026-08-15']);

        $this->get(route('workdays.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page->where('workdays.0.id', $september->id)
        );
    }

    public function test_a_malformed_month_falls_back_to_the_current_month(): void
    {
        $this->travelTo('2026-09-11 12:00:00');

        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);
        $workday = Workday::factory()->create(['agency_id' => $agency->id, 'date' => '2026-09-15']);

        $this->get(route('workdays.index', ['month' => '2026-13']))->assertInertia(
            fn (Assert $page) => $page
                ->where('filters.month', '2026-09')
                ->has('workdays', 1)
                ->where('workdays.0.id', $workday->id)
        );
    }

    public function test_an_unknown_employee_or_status_filter_is_reported_as_unset(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);
        Workday::factory()->create(['agency_id' => $agency->id, 'date' => '2026-09-15']);

        $this->get(route('workdays.index', ['month' => '2026-09', 'employee' => 'nonsense', 'status' => 'nope']))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('filters.employee', '')
                    ->where('filters.status', '')
                    ->has('workdays', 1)
            );
    }

    public function test_the_attention_filter_finds_absences_and_unfilled_punches(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $present = Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-01',
            'status' => WorkdayStatus::Present,
        ]);
        Punch::factory()->create([
            'agency_id' => $present->agency_id,
            'employee_id' => $present->employee_id,
            'workday_id' => $present->id,
            'expected_at' => '2026-09-01 08:00:00',
            'actual_at' => '2026-09-01 08:00:00',
            'deviation' => 0,
        ]);

        $absent = Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-02',
            'status' => WorkdayStatus::Absent,
        ]);

        $open = Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-03',
            'status' => WorkdayStatus::Present,
        ]);
        Punch::factory()->missed()->create([
            'agency_id' => $open->agency_id,
            'employee_id' => $open->employee_id,
            'workday_id' => $open->id,
            'expected_at' => '2026-09-03 08:00:00',
        ]);

        $this->get(route('workdays.index', ['month' => '2026-09', 'attention' => 1]))->assertInertia(
            fn (Assert $page) => $page
                ->has('workdays', 2)
                ->where('filters.attention', true)
                ->where('workdays.0.id', $absent->id)
                ->where('workdays.1.id', $open->id)
        );
    }

    public function test_the_list_can_be_filtered_by_employee_and_by_status(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $mine = Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-01',
            'status' => WorkdayStatus::Absent,
        ]);
        Workday::factory()->create([
            'agency_id' => $agency->id,
            'date' => '2026-09-01',
            'status' => WorkdayStatus::Present,
        ]);

        $this->get(route('workdays.index', [
            'month' => '2026-09',
            'employee' => $employee->id,
            'status' => WorkdayStatus::Absent->value,
        ]))->assertInertia(
            fn (Assert $page) => $page
                ->has('workdays', 1)
                ->where('workdays.0.id', $mine->id)
                ->where('filters.employee', $employee->id)
                ->where('filters.status', 'absent')
        );
    }

    public function test_the_employee_picker_is_this_agencys_people(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $mine = Employee::factory()->create(['agency_id' => $agency->id, 'last_name' => 'Abadilla']);
        Employee::factory()->create(['last_name' => 'Zzz Other']);

        $this->get(route('workdays.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page->has('employees', 1)->where('employees.0.id', $mine->id)
        );
    }

    public function test_a_removed_employees_september_still_carries_their_name(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $employee = Employee::factory()->create([
            'agency_id' => $agency->id,
            'first_name' => 'Amihan',
            'middle_name' => null,
            'last_name' => 'Reyes',
            'suffix' => null,
        ]);
        $workday = Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-15',
        ]);

        $this->withTenant($agency);
        app(RemoveEmployee::class)->handle($employee->fresh());

        $this->get(route('workdays.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page
                ->where('workdays.0.id', $workday->id)
                ->where('workdays.0.employee.name', 'Amihan Reyes')
        );
    }

    public function test_listing_many_workdays_issues_the_same_queries_as_one(): void
    {
        $this->travelTo('2026-09-11 12:00:00');

        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Treasury']);
        $this->dayOn($agency, $workgroup, '2026-09-01', ['last_name' => 'Aaa']);

        // The first request hydrates the acting user; counting starts after that.
        $this->get(route('workdays.index', ['month' => '2026-09']));

        $queries = 0;
        $counting = false;
        DB::listen(function () use (&$queries, &$counting): void {
            if ($counting) {
                $queries++;
            }
        });

        $counting = true;
        $this->get(route('workdays.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page->where('workdays.0.employee.current_deployment.workgroup.name', 'Treasury')
        );
        $counting = false;
        $few = $queries;

        foreach (range(2, 15) as $n) {
            $this->dayOn($agency, $workgroup, '2026-09-15', ['last_name' => sprintf('Zzz %02d', $n)]);
        }

        $queries = 0;
        $counting = true;
        $this->get(route('workdays.index', ['month' => '2026-09']));
        $counting = false;

        $this->assertSame($few, $queries);
        $this->assertGreaterThan(0, $few);
    }

    /**
     * @param  array<string, mixed>  $employee
     */
    private function dayOn(Agency $agency, Workgroup $workgroup, string $date, array $employee = []): Workday
    {
        $person = Employee::factory()->create([
            'agency_id' => $agency->id,
            ...$employee,
        ]);
        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $person->id,
            'workgroup_id' => $workgroup->id,
            'starts' => '2020-01-01',
            'ends' => null,
        ]);

        return Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $person->id,
            'date' => $date,
        ]);
    }
}
