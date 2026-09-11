<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Enums\WorkdayStatus;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Punch;
use App\Models\Workday;
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
                ->component('workdays/index', false)
                ->has('workdays', 1)
                ->where('workdays.0.id', $mine->id)
                ->where('workdays.0.date', '2026-09-15')
                ->where('workdays.0.shift_name', 'Standard')
                ->where('workdays.0.worked', 480)
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

    /** A mangled query string must not leave the list filtered by something the picker cannot show. */
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

    /**
     * The filter this screen exists for: status is absent, or any punch of
     * the day has a null `actual_at`.
     */
    public function test_the_attention_filter_finds_absences_and_unfilled_punches(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $ledger = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);

        $present = Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
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
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
            'date' => '2026-09-02',
            'status' => WorkdayStatus::Absent,
        ]);

        $open = Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
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

        $ledger = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);
        $mine = Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
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
            'employee' => $ledger->employee_id,
            'status' => WorkdayStatus::Absent->value,
        ]))->assertInertia(
            fn (Assert $page) => $page
                ->has('workdays', 1)
                ->where('workdays.0.id', $mine->id)
                ->where('filters.employee', $ledger->employee_id)
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
}
