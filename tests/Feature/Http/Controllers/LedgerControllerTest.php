<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Attestation;
use App\Models\Ledger;
use App\Models\Punch;
use App\Models\Workday;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LedgerControllerTest extends TestCase
{
    public function test_viewing_requires_ledgers_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewCalendar);

        $this->get(route('ledgers.index'))->assertForbidden();
    }

    public function test_the_index_lists_this_agencys_ledgers_only(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $mine = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);
        Ledger::factory()->create(['month' => '2026-09-01']);

        $this->get(route('ledgers.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page
                ->component('ledgers/index', false)
                ->has('ledgers', 1)
                ->where('ledgers.0.id', $mine->id)
                ->where('ledgers.0.month', '2026-09-01')
        );
    }

    /**
     * The index answers "whose month is done" with aggregates, never a view.
     * Overtime is a figure of the record and belongs on the DTR page.
     */
    public function test_the_index_carries_aggregates_not_a_view(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $ledger = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);
        Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
            'date' => '2026-09-15',
            'worked' => 480,
            'tardy' => 15,
            'undertime' => 10,
        ]);

        $this->get(route('ledgers.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page
                ->where('ledgers.0.workdays_count', 1)
                ->where('ledgers.0.worked', 480)
                ->where('ledgers.0.tardy', 15)
                ->where('ledgers.0.undertime', 10)
                ->missing('ledgers.0.overtime')
        );
    }

    public function test_the_record_includes_the_derived_view(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageLedgers);

        $ledger = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);
        $workday = Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
            'date' => '2026-09-15',
            'worked' => 480,
            'tardy' => 15,
        ]);
        Punch::factory()->missed()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id,
            'expected_at' => '2026-09-15 08:00:00',
        ]);

        $this->get(route('ledgers.show', $ledger))->assertInertia(
            fn (Assert $page) => $page
                ->component('ledgers/show', false)
                ->where('ledger.id', $ledger->id)
                ->where('ledger.month', '2026-09-01')
                ->where('view.worked', 480)
                ->where('view.tardy', 15)
                ->has('view.overtime')
                ->has('view.workdays', 1)
                ->where('view.workdays.0.date', '2026-09-15')
                ->where('view.workdays.0.shift_name', 'Standard')
                ->has('view.workdays.0.punches', 1)
                ->where('can.lock', true)
                ->where('can.unlock', true)
        );
    }

    public function test_an_unknown_period_falls_back_to_the_whole_month(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $ledger = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);
        Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
            'date' => '2026-09-20',
            'worked' => 480,
        ]);

        $this->get(route('ledgers.show', [$ledger, 'period' => 'nope', 'work' => 'nope']))
            ->assertInertia(fn (Assert $page) => $page->has('view.workdays', 1)->where('view.worked', 480));

        $this->get(route('ledgers.show', [$ledger, 'period' => 'first']))
            ->assertInertia(fn (Assert $page) => $page->has('view.workdays', 0));
    }

    public function test_another_agencys_ledger_is_not_found(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageLedgers);
        $theirs = Ledger::factory()->create(['month' => '2026-09-01']);

        $this->get(route('ledgers.show', $theirs))->assertNotFound();
        $this->patch(route('ledgers.lock', $theirs))->assertNotFound();
    }

    public function test_locking_requires_ledgers_manage(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);
        $ledger = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);

        $this->patch(route('ledgers.lock', $ledger))->assertForbidden();
        $this->patch(route('ledgers.unlock', $ledger))->assertForbidden();
    }

    public function test_a_lock_sets_locked_at(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageLedgers);
        $ledger = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);

        $this->travelTo('2026-09-11 12:00:00');

        $this->patch(route('ledgers.lock', $ledger))->assertSessionHas('success');

        $this->assertSame('2026-09-11 12:00:00', $ledger->fresh()->locked_at->toDateTimeString());
    }

    public function test_an_unlock_clears_locked_at(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageLedgers);
        $ledger = Ledger::factory()->locked()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);

        $this->patch(route('ledgers.unlock', $ledger))->assertSessionHas('success');

        $this->assertNull($ledger->fresh()->locked_at);
    }

    /**
     * `ledgers_lock_complete` is reachable from the UI by a user doing nothing
     * wrong, and must flash rather than 500. The action's own transaction is
     * what keeps the next assertion from answering 25P02.
     */
    public function test_a_lock_with_a_punch_still_due_flashes_rather_than_500(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageLedgers);
        $ledger = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);
        $workday = Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
            'date' => '2026-09-15',
        ]);
        Punch::factory()->missed()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id,
            'expected_at' => '2026-09-15 08:00:00',
        ]);

        $this->travelTo('2026-09-11 12:00:00');

        $this->patch(route('ledgers.lock', $ledger))->assertSessionHas(
            'error',
            'This month still has a punch due. It can be locked once the last shift has ended.',
        );

        $this->assertNull($ledger->fresh()->locked_at);
    }

    /** `ledgers_unlock_clean`: you certify frozen numbers, never moving ones. */
    public function test_an_unlock_of_an_attested_ledger_flashes_rather_than_500(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageLedgers);
        $ledger = Ledger::factory()->locked()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);
        Attestation::factory()->create([
            'agency_id' => $agency->id,
            'ledger_id' => $ledger->id,
        ]);

        $this->patch(route('ledgers.unlock', $ledger))->assertSessionHas(
            'error',
            'Remove the signatures before unlocking. You certify frozen numbers, never moving ones.',
        );

        $this->assertNotNull($ledger->fresh()->locked_at);
    }

    public function test_a_malformed_month_falls_back_to_the_current_month(): void
    {
        $this->travelTo('2026-09-11 12:00:00');

        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);
        $ledger = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);

        $this->get(route('ledgers.index', ['month' => 'nope']))->assertInertia(
            fn (Assert $page) => $page
                ->where('filters.month', '2026-09')
                ->has('ledgers', 1)
                ->where('ledgers.0.id', $ledger->id)
        );
    }
}
