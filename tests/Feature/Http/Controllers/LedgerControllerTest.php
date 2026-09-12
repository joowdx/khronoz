<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\LockLedger;
use App\Enums\Permission;
use App\Enums\Work;
use App\Models\Agency;
use App\Models\Cadence;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Ledger;
use App\Models\Policy;
use App\Models\User;
use App\Models\Workday;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LedgerControllerTest extends TestCase
{
    public function test_guests_must_sign_in(): void
    {
        $this->get(route('ledgers.index'))->assertRedirect(route('login'));
    }

    public function test_viewing_requires_ledgers_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewOrganization);
        $this->get(route('ledgers.index'))->assertForbidden();
    }

    public function test_the_index_lists_this_agencys_ranges_only(): void
    {
        Ledger::factory()->create();
        [$agency, $employee, $actor] = $this->staff();
        $ledger = $this->lock($employee, $actor);
        $this->get(route('ledgers.index', ['month' => '2026-08']))->assertInertia(fn (Assert $page) => $page
            ->component('ledgers/index')->has('ledgers', 1)->where('ledgers.0.id', $ledger->id)
            ->where('ledgers.0.starts', '2026-08-01')->where('ledgers.0.ends', '2026-08-31'));
    }

    public function test_the_index_filters_by_range_overlap(): void
    {
        [$agency, $employee, $actor] = $this->staff();
        $ledger = $this->lock($employee, $actor);
        $this->get(route('ledgers.index', ['month' => '2026-07']))->assertInertia(fn (Assert $page) => $page->has('ledgers', 0));
        $this->get(route('ledgers.index', ['month' => '2026-08']))->assertInertia(fn (Assert $page) => $page->where('ledgers.0.id', $ledger->id));
    }

    public function test_the_index_carries_aggregates_without_daily_rows(): void
    {
        [$agency, $employee, $actor] = $this->staff();
        Workday::factory()->create(['agency_id' => $agency->id, 'employee_id' => $employee->id, 'date' => '2026-08-15', 'worked' => 480, 'tardy' => 15, 'undertime' => 10]);
        $this->lock($employee, $actor);
        $this->get(route('ledgers.index', ['month' => '2026-08']))->assertInertia(fn (Assert $page) => $page
            ->where('ledgers.0.workdays_count', 1)->where('ledgers.0.worked', 480)
            ->where('ledgers.0.tardy', 15)->where('ledgers.0.undertime', 10)->missing('ledgers.0.overtime')->missing('ledgers.0.workdays'));
    }

    public function test_show_uses_frozen_identity_and_workday_figures(): void
    {
        [$agency, $employee, $actor] = $this->staff();
        $exemption = Exemption::factory()->personal()->create(['agency_id' => $agency->id, 'employee_id' => $employee->id, 'date' => '2026-08-15']);
        Workday::factory()->create(['agency_id' => $agency->id, 'employee_id' => $employee->id, 'date' => '2026-08-15', 'worked' => 480, 'tardy' => 15, 'exemption_id' => $exemption->id]);
        $ledger = $this->lock($employee, $actor);
        $frozenName = $employee->name;
        $employee->update(['first_name' => 'Changed']);
        $this->get(route('ledgers.show', $ledger))->assertInertia(fn (Assert $page) => $page
            ->component('ledgers/show')->where('ledger.employee.name', $frozenName)
            ->where('view.worked', 480)->where('view.tardy', 15)->has('view.workdays', 1)
            ->where('view.workdays.0.shift_name', 'Standard')->where('view.workdays.0.exemption.id', $exemption->id)
            ->where('can.unlock', true)->where('can.attest', true));
    }

    public function test_removed_employees_keep_their_historical_record(): void
    {
        [$agency, $employee, $actor] = $this->staff();
        $ledger = $this->lock($employee, $actor);
        $employee->delete();
        $this->get(route('ledgers.show', $ledger))->assertInertia(fn (Assert $page) => $page->where('ledger.employee.name', $employee->name));
    }

    public function test_cross_tenant_show_and_unlock_are_not_found(): void
    {
        $ledger = Ledger::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageLedgers);
        $this->get(route('ledgers.show', $ledger))->assertNotFound();
        $this->post(route('ledgers.unlock', $ledger))->assertNotFound();
    }

    public function test_locking_requires_management_or_the_employees_own_account(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ViewLedgers);
        $this->post(route('ledgers.lock'), $this->range($employee))->assertForbidden();
        $this->assertDatabaseCount('ledgers', 0);
    }

    public function test_post_lock_creates_the_first_record_and_freezes_the_actor(): void
    {
        [$agency, $employee, $actor] = $this->staff();
        $this->post(route('ledgers.lock'), $this->range($employee))->assertSessionHas('success');
        $this->assertDatabaseHas('ledgers', ['employee_id' => $employee->id, 'starts' => '2026-08-01', 'ends' => '2026-08-31', 'locked_by' => $actor->id, 'revision' => 1]);
        $this->assertDatabaseCount('ledgers', 1);
    }

    public function test_a_manager_may_use_a_retired_agency_cadence_for_a_retroactive_range(): void
    {
        [$agency, $employee] = $this->staff();
        $cadence = Cadence::factory()->create([
            'agency_id' => $agency->id,
            'kind' => 'semimonthly',
            'rules' => ['starts' => [1, 16]],
            'retired_at' => now(),
        ]);

        $this->post(route('ledgers.lock'), [
            'employee_id' => $employee->id,
            'cadence_id' => $cadence->id,
            'starts' => '2026-08-16',
            'ends' => '2026-08-31',
            'scope' => 'all',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ledgers', ['employee_id' => $employee->id, 'cadence_id' => $cadence->id]);
    }

    public function test_invalid_or_future_ranges_do_not_create_records(): void
    {
        [$agency, $employee] = $this->staff();
        $this->post(route('ledgers.lock'), [...$this->range($employee), 'ends' => '2026-12-31'])->assertSessionHasErrors('ends');
        $this->assertDatabaseCount('ledgers', 0);
    }

    public function test_a_cross_tenant_employee_cannot_be_locked(): void
    {
        $other = Employee::factory()->create();
        [$agency] = $this->staff();
        $this->post(route('ledgers.lock'), $this->range($other))->assertNotFound();
        $this->assertDatabaseCount('ledgers', 0);
    }

    public function test_unlock_records_the_actor_and_preserves_the_original_lock(): void
    {
        [$agency, $employee, $actor] = $this->staff();
        $ledger = $this->lock($employee, $actor);
        $lockedAt = $ledger->locked_at->toDateTimeString();
        $this->post(route('ledgers.unlock', $ledger))->assertSessionHas('success');
        $this->assertSame($lockedAt, $ledger->fresh()->locked_at->toDateTimeString());
        $this->assertNotNull($ledger->fresh()->unlocked_at);
        $this->assertSame($actor->id, $ledger->fresh()->unlocked_by);
    }

    public function test_relocking_creates_a_new_revision(): void
    {
        [$agency, $employee, $actor] = $this->staff();
        $ledger = $this->lock($employee, $actor);
        $this->post(route('ledgers.unlock', $ledger))->assertSessionHas('success');
        $this->post(route('ledgers.lock'), $this->range($employee))->assertSessionHas('success');
        $this->assertDatabaseHas('ledgers', ['employee_id' => $employee->id, 'revision' => 2, 'unlocked_at' => null]);
        $this->assertDatabaseCount('ledgers', 2);
    }

    public function test_unlocked_history_keeps_its_frozen_index_totals_after_recomputation(): void
    {
        [$agency, $employee, $actor] = $this->staff();
        $day = Workday::factory()->create(['agency_id' => $agency->id, 'employee_id' => $employee->id, 'date' => '2026-08-15', 'worked' => 480]);
        $ledger = $this->lock($employee, $actor);
        $this->post(route('ledgers.unlock', $ledger))->assertSessionHas('success');
        $day->update(['worked' => 420]);

        $this->get(route('ledgers.index', ['month' => '2026-08']))->assertInertia(fn (Assert $page) => $page->where('ledgers.0.worked', 480));
    }

    public function test_attestation_and_withdrawal_are_recorded_and_unlock_is_guarded(): void
    {
        [$agency, $employee, $actor] = $this->staff();
        $ledger = $this->lock($employee, $actor);
        $this->post(route('ledgers.attestations.store', $ledger), ['role' => 'timekeeper'])->assertSessionHas('success');
        $attestation = $ledger->attestations()->firstOrFail();
        $this->assertDatabaseHas('renditions', ['ledger_id' => $ledger->id, 'status' => 'unstored', 'document_id' => null]);
        $this->post(route('ledgers.unlock', $ledger))->assertSessionHasErrors('ledger');
        $this->delete(route('ledgers.attestations.destroy', [$ledger, $attestation]))->assertSessionHas('success');
        $this->assertNotNull($attestation->fresh()->withdrawn_at);
        $this->assertNotNull($ledger->renditions()->firstOrFail()->superseded_at);
    }

    public function test_attestation_nested_binding_rejects_another_ledger(): void
    {
        [$agency, $employee, $actor] = $this->staff();
        $ledger = $this->lock($employee, $actor);
        $other = Ledger::factory()->create(['agency_id' => $agency->id]);
        $this->post(route('ledgers.attestations.store', $ledger))->assertSessionHas('success');
        $attestation = $ledger->attestations()->firstOrFail();
        $this->delete(route('ledgers.attestations.destroy', [$other, $attestation]))->assertNotFound();
    }

    public function test_index_query_count_does_not_grow_per_ledger(): void
    {
        [$agency, $employee, $actor] = $this->staff();
        $this->lock($employee, $actor);
        $this->get(route('ledgers.index', ['month' => '2026-08']));
        $queries = 0;
        $counting = false;
        DB::listen(function () use (&$queries, &$counting): void {
            if ($counting) {
                $queries++;
            }
        });
        $counting = true;
        $this->get(route('ledgers.index', ['month' => '2026-08']))->assertOk();
        $counting = false;
        $few = $queries;
        foreach (range(1, 5) as $number) {
            $this->lock(Employee::factory()->create(['agency_id' => $agency->id]), $actor);
        }
        $queries = 0;
        $counting = true;
        $this->get(route('ledgers.index', ['month' => '2026-08']))->assertOk();
        $counting = false;
        $this->assertSame($few, $queries);
    }

    private function staff(): array
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $actor = $this->actingAsAgency($agency, Permission::ManageLedgers, Permission::AttestLedgers);
        Policy::factory()->create(['agency_id' => $agency->id, 'template' => 'plain', 'roles' => ['timekeeper']]);
        $this->withTenant($agency);

        return [$agency, $employee, $actor];
    }

    private function lock(Employee $employee, User $actor): Ledger
    {
        return app(LockLedger::class)->handle($employee, '2026-08-01', '2026-08-31', Work::All, $actor);
    }

    private function range(Employee $employee): array
    {
        return ['employee_id' => $employee->id, 'starts' => '2026-08-01', 'ends' => '2026-08-31', 'scope' => 'all'];
    }
}
