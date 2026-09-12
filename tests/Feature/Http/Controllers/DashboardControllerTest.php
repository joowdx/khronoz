<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Enums\PunchKind;
use App\Enums\RenditionStatus;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Ledger;
use App\Models\Overtime;
use App\Models\Punch;
use App\Models\Rendition;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workday;
use App\Models\Workgroup;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_shows_the_current_agency_counts(): void
    {
        $user = User::factory()->create();
        User::factory()->forAgency($user->agency)->count(2)->create();
        User::factory()->forAgency($user->agency)->invited()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')
                ->where('counts.users', 4)
                ->where('counts.active', 3)
                ->where('counts.invited', 1));
    }

    public function test_dashboard_counts_only_the_current_agencys_users(): void
    {
        $user = User::factory()->create();
        User::factory()->forAgency($user->agency)->count(2)->create();
        User::factory()->forAgency($user->agency)->invited()->create();

        $other = Agency::factory()->create();
        User::factory()->forAgency($other)->count(5)->create();
        User::factory()->forAgency($other)->invited()->count(4)->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.users', 4)
                ->where('counts.active', 3)
                ->where('counts.invited', 1));
    }

    public function test_dashboard_shows_the_platform_figures_for_the_platform_tenant(): void
    {
        $staffed = Agency::factory()->create();
        User::factory()->forAgency($staffed)->count(3)->create();
        Agency::factory()->count(2)->create();

        $this->actingAsPlatform();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')
                ->where('counts.agencies', 3)
                ->where('counts.agency_users', 3)
                ->where('counts.empty_agencies', 2)
                ->where('counts.users', 1));
    }

    public function test_dashboard_omits_the_platform_figures_for_an_ordinary_agency_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('counts.agencies')
                ->missing('counts.agency_users')
                ->missing('counts.empty_agencies'));
    }

    public function test_dashboard_omits_the_platform_figures_once_a_platform_user_has_entered_an_agency(): void
    {
        $agency = Agency::factory()->create();
        User::factory()->forAgency($agency)->count(2)->create();
        $this->actingAsPlatform(enter: $agency);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('counts.agencies')
                ->where('counts.users', 2));
    }

    public function test_a_tenant_with_nothing_outstanding_reports_zero_rather_than_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.invited', 0));
    }

    public function test_an_agency_whose_users_have_all_signed_in_reports_no_empty_agencies(): void
    {
        $staffed = Agency::factory()->create();
        User::factory()->forAgency($staffed)->create();

        $this->actingAsPlatform();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.empty_agencies', 0));
    }

    public function test_accepting_an_invitation_moves_a_user_from_invited_to_active(): void
    {
        $user = User::factory()->create();
        $invitee = User::factory()->forAgency($user->agency)->invited()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.active', 1)
                ->where('counts.invited', 1));

        $invitee->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.active', 2)
                ->where('counts.invited', 0));
    }

    public function test_the_dashboard_reports_the_month_for_an_agency_tenant(): void
    {
        $this->travelTo('2026-09-09 14:42:00');

        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers, Permission::ViewScheduling, Permission::ViewTerminals);

        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'exempt' => false]);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Nursing Service']);
        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroup->id,
            'parent_id' => null,
            'starts' => '2026-09-01',
            'ends' => null,
        ]);

        $morning = Shift::factory()->create(['agency_id' => $agency->id, 'name' => 'Morning', 'color' => 2]);
        $schedule = Schedule::factory()->withTurns($morning)->create(['agency_id' => $agency->id]);
        Roster::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-01',
        ]);

        Ledger::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'starts' => '2026-09-01',
            'ends' => '2026-09-07',
        ]);

        // Yesterday, standing in for the night shift: tardy, and an out side
        // that fell inside this morning's window and nothing has filled.
        $yesterday = Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-08',
            'tardy' => 12,
        ]);

        Punch::factory()->missed()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workday_id' => $yesterday->id,
            'slot' => 1,
            'kind' => PunchKind::Out,
            'expected_at' => '2026-09-09 06:00:00',
        ]);

        // Today: a late arrival, and a departure that was due two hours ago.
        $todaysWorkday = Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-09',
        ]);

        Punch::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workday_id' => $todaysWorkday->id,
            'slot' => 1,
            'kind' => PunchKind::In,
            'expected_at' => '2026-09-09 08:00:00',
            'actual_at' => '2026-09-09 08:21:00',
            'deviation' => 21,
        ]);

        Punch::factory()->missed()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workday_id' => $todaysWorkday->id,
            'slot' => 1,
            'kind' => PunchKind::Out,
            'expected_at' => '2026-09-09 12:00:00',
        ]);

        Exemption::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-09',
        ]);

        Overtime::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'starts' => '2026-09-09 17:00:00',
            'ends' => '2026-09-09 20:00:00',
        ]);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('month.value', '2026-09')
            ->where('month.previous', '2026-08')
            ->where('month.through', '2026-09-09')
            ->where('month.days', 9)
            ->where('figures.workdays.value', 2)
            ->where('figures.workdays.previous', 0)
            ->where('figures.tardy.value', 1)
            ->where('figures.overtime.value', 1)
            ->where('ledgers.total', 1)
            // Four sources, one list ordered by the clock: the whole-day leave
            // sorts to 00:00, then the 08:21 arrival, the 12:00 departure that
            // never came, and the authority filed for 17:00.
            ->where('today.total', 4)
            ->has('today.events', 4)
            ->where('today.events.0.kind.value', 'leave')
            ->where('today.events.1.kind.value', 'late_in')
            ->where('today.events.1.time', '08:21')
            ->where('today.events.1.detail', 'Expected 08:00')
            ->where('today.events.2.kind.value', 'missed_out')
            ->where('today.events.3.kind.value', 'overtime')
            ->where('today.events.3.time', '17:00 – 20:00')
            ->has('night_outs', 1)
            ->where('night_outs.0.since', '06:00')
            ->where('night_outs.0.shift', '08:00 – 17:00')
            ->where('tardiness.total', 1)
            ->where('tardiness.workgroups.0.name', 'Nursing Service')
            ->where('duty.date', '2026-09-09')
            ->has('duty.lanes', 1)
            ->where('duty.lanes.0.name', 'Morning')
            ->where('duty.lanes.0.slot', 2)
            ->where('duty.lanes.0.count', 1)
            // 08:00–12:00 and 13:00–17:00, as minutes from 06:00.
            ->where('duty.lanes.0.bars.0.from', 120)
            ->where('duty.lanes.0.bars.0.to', 360)
            ->where('duty.lanes.0.bars.0.label', '08:00 – 12:00')
            ->where('counts.without_roster', 0)
            ->where('counts.unresolved_timelogs', 0));
    }

    public function test_ledger_counts_report_active_rendition_states_without_waiting_for_lock(): void
    {
        $agency = Agency::factory()->create();
        $actor = $this->actingAsAgency($agency, Permission::ViewLedgers);
        $ledger = fn (): Ledger => Ledger::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => Employee::factory()->create(['agency_id' => $agency->id])->id,
            'starts' => '2026-09-01',
            'ends' => '2026-09-12',
        ]);

        $ledger();
        Rendition::factory()->create(['agency_id' => $agency->id, 'ledger_id' => $ledger()->id]);
        Rendition::factory()->create([
            'agency_id' => $agency->id,
            'ledger_id' => $ledger()->id,
            'status' => RenditionStatus::Pending,
            'requested_at' => now(),
        ]);
        Rendition::factory()->create([
            'agency_id' => $agency->id,
            'ledger_id' => $ledger()->id,
            'status' => RenditionStatus::Failed,
            'requested_at' => now(),
            'failed_at' => now(),
            'error' => 'Generation failed.',
        ]);
        Ledger::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => Employee::factory()->create(['agency_id' => $agency->id])->id,
            'starts' => '2026-09-01',
            'ends' => '2026-09-12',
            'unlocked_at' => now(),
            'unlocked_by' => $actor->id,
        ]);

        $this->get(route('dashboard', ['month' => '2026-09']))->assertInertia(fn (Assert $page) => $page
            ->where('ledgers.total', 4)
            ->where('ledgers.awaiting', 1)
            ->where('ledgers.preparing', 1)
            ->where('ledgers.ready', 1)
            ->where('ledgers.failed', 1)
            ->missing('ledgers.lockable'));
    }

    public function test_the_dashboard_omits_every_month_scoped_section_for_the_platform_tenant(): void
    {
        Agency::factory()->create();

        $this->actingAsPlatform();

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('counts.agencies')
            ->missing('month')
            ->missing('figures')
            ->missing('ledgers')
            ->missing('today')
            ->missing('night_outs')
            ->missing('tardiness')
            ->missing('duty')
            ->missing('counts.without_roster')
            ->missing('counts.unresolved_timelogs'));
    }
}
