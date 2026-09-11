<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\RemoveEmployee;
use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Attestation;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Ledger;
use App\Models\Overtime;
use App\Models\Punch;
use App\Models\Workday;
use App\Models\Workgroup;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LedgerControllerTest extends TestCase
{
    public function test_viewing_requires_ledgers_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewCalendar);

        $this->get(route('ledgers.index'))->assertForbidden();
    }

    public function test_viewing_the_record_requires_ledgers_view(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewCalendar);
        $ledger = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);

        $this->get(route('ledgers.show', $ledger))->assertForbidden();
    }

    public function test_the_index_lists_this_agencys_ledgers_only(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $mine = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);
        Ledger::factory()->create(['month' => '2026-09-01']);

        $this->get(route('ledgers.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page
                ->component('ledgers/index')
                ->has('ledgers', 1)
                ->where('ledgers.0.id', $mine->id)
                ->where('ledgers.0.month', '2026-09-01')
        );
    }

    /**
     * August sorts first by last name, so a missing month filter would hand
     * that row back as `ledgers.0`. The assertion is the September id.
     */
    public function test_the_index_is_scoped_to_the_requested_month(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $september = Ledger::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => Employee::factory()->create([
                'agency_id' => $agency->id,
                'last_name' => 'Zzz',
            ]),
            'month' => '2026-09-01',
        ]);
        Ledger::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => Employee::factory()->create([
                'agency_id' => $agency->id,
                'last_name' => 'Aaa',
            ]),
            'month' => '2026-08-01',
        ]);

        $this->get(route('ledgers.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page->where('ledgers.0.id', $september->id)
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
                ->component('ledgers/show')
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

    public function test_regular_work_reports_overtime_as_zero(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $ledger = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);
        Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
            'date' => '2026-09-01',
            'worked' => 480,
            'excess' => 180,
        ]);
        Overtime::factory()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'starts' => '2026-09-01 17:00:00',
            'ends' => '2026-09-01 21:00:00',
        ]);

        $this->get(route('ledgers.show', $ledger))
            ->assertInertia(fn (Assert $page) => $page->where('view.overtime', 180));

        $this->get(route('ledgers.show', [$ledger, 'work' => 'regular']))
            ->assertInertia(fn (Assert $page) => $page->where('view.overtime', 0));
    }

    /**
     * A DTR is a historical pay record. RemoveEmployee soft-deletes; without
     * `withTrashed()` the row is listed nameless through the living-rows scope.
     */
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
        $ledger = Ledger::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'month' => '2026-09-01',
        ]);
        Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'ledger_id' => $ledger->id,
            'date' => '2026-09-15',
        ]);

        $this->withTenant($agency);
        app(RemoveEmployee::class)->handle($employee->fresh());

        $this->get(route('ledgers.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page
                ->where('ledgers.0.id', $ledger->id)
                ->where('ledgers.0.employee.name', 'Amihan Reyes')
        );

        $this->get(route('ledgers.show', $ledger))->assertInertia(
            fn (Assert $page) => $page->where('ledger.employee.name', 'Amihan Reyes')
        );
    }

    /**
     * Daily rule 7: a personal slip on an otherwise present day is still
     * recorded and printed. The one-argument `whenLoaded` would crash because
     * `exemption_id` is nullable.
     */
    public function test_the_record_sends_a_stamped_exemption(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $ledger = Ledger::factory()->create(['agency_id' => $agency->id, 'month' => '2026-09-01']);
        $exemption = Exemption::factory()->personal()->create([
            'agency_id' => $agency->id,
            'employee_id' => $ledger->employee_id,
            'date' => '2026-09-15',
            'reference' => 'Locator 42',
        ]);
        Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
            'date' => '2026-09-15',
            'exemption_id' => $exemption->id,
        ]);

        $this->get(route('ledgers.show', $ledger))->assertInertia(
            fn (Assert $page) => $page
                ->where('view.workdays.0.exemption.id', $exemption->id)
                ->where('view.workdays.0.exemption.type.value', 'personal')
                ->where('view.workdays.0.exemption.type.label', 'Personal locator slip')
                ->where('view.workdays.0.exemption.reference', 'Locator 42')
        );
    }

    /**
     * `currentDeployment.workgroup` and `exemption` are eager-loaded on a
     * page of rows. Model::shouldBeStrict() only arms the lazy-loading guard
     * once a collection holds more than one model, so one row cannot catch
     * a missing nested load — only one row versus many, same query count.
     */
    public function test_listing_many_ledgers_issues_the_same_queries_as_one(): void
    {
        $this->travelTo('2026-09-11 12:00:00');

        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Treasury']);
        $this->placeOn($agency, $workgroup, ['last_name' => 'Aaa']);

        // The first request hydrates the acting user; counting starts after that.
        $this->get(route('ledgers.index', ['month' => '2026-09']));

        $queries = 0;
        $counting = false;
        DB::listen(function () use (&$queries, &$counting): void {
            if ($counting) {
                $queries++;
            }
        });

        $counting = true;
        $this->get(route('ledgers.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page->where('ledgers.0.employee.current_deployment.workgroup.name', 'Treasury')
        );
        $counting = false;
        $few = $queries;

        foreach (range(1, 14) as $n) {
            $this->placeOn($agency, $workgroup, ['last_name' => sprintf('Zzz %02d', $n)]);
        }

        $queries = 0;
        $counting = true;
        $this->get(route('ledgers.index', ['month' => '2026-09']));
        $counting = false;

        $this->assertSame($few, $queries);
        $this->assertGreaterThan(0, $few);
    }

    public function test_loading_many_days_on_the_record_issues_the_same_queries_as_one(): void
    {
        $this->travelTo('2026-09-11 12:00:00');

        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewLedgers);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Treasury']);
        $ledger = $this->placeOn($agency, $workgroup);
        $this->stampDay($ledger, '2026-09-01');

        // The first request hydrates the acting user; counting starts after that.
        $this->get(route('ledgers.show', $ledger));

        $queries = 0;
        $counting = false;
        DB::listen(function () use (&$queries, &$counting): void {
            if ($counting) {
                $queries++;
            }
        });

        $counting = true;
        $this->get(route('ledgers.show', $ledger))->assertInertia(
            fn (Assert $page) => $page
                ->where('ledger.employee.current_deployment.workgroup.name', 'Treasury')
                ->where('view.workdays.0.exemption.type.value', 'personal')
        );
        $counting = false;
        $few = $queries;

        foreach (range(2, 15) as $day) {
            $this->stampDay($ledger, sprintf('2026-09-%02d', $day));
        }

        $queries = 0;
        $counting = true;
        $this->get(route('ledgers.show', $ledger));
        $counting = false;

        $this->assertSame($few, $queries);
        $this->assertGreaterThan(0, $few);
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

    /**
     * @param  array<string, mixed>  $employee
     */
    private function placeOn(Agency $agency, Workgroup $workgroup, array $employee = []): Ledger
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

        return Ledger::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $person->id,
            'month' => '2026-09-01',
        ]);
    }

    private function stampDay(Ledger $ledger, string $date): Workday
    {
        $exemption = Exemption::factory()->personal()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'date' => $date,
        ]);

        return Workday::factory()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
            'date' => $date,
            'exemption_id' => $exemption->id,
        ]);
    }
}
