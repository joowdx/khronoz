<?php

namespace Tests\Feature\Actions;

use App\Actions\RemoveEmployee;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RemoveEmployeeTest extends TestCase
{
    /**
     * The defect an adversarial review found on 2026-09-11, and the reason a
     * missed compare-and-swap now aborts instead of being skipped.
     *
     * It looked safe on an open row: a CAS on `ends IS NULL` can only miss
     * because someone else *closed* the row, which is the outcome anyway. A
     * **fixed-term** row breaks that reasoning — the expected value is a date,
     * so a concurrent correction that moves it to a later date makes the CAS
     * miss while leaving the placement unfinished, and the employee would have
     * been soft-deleted holding it. Under decision 30 that leaves their
     * records visible to a workgroup indefinitely.
     *
     * The stale expectation is injected by changing the row after the action
     * has loaded it — done here by making the loaded value wrong rather than
     * by real interleaving, which one test process cannot produce.
     */
    public function test_a_placement_changing_mid_removal_aborts_the_whole_removal(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 00:30:00'));
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => '2026-12-31']);
        $this->withTenant(Agency::findOrFail($placement->agency_id));
        $employee = $placement->employee;

        // Stand in for a concurrent correction: the action's own SELECT will
        // read this value, and the listener moves it again before the UPDATE,
        // so the expected-value predicate misses.
        Deployment::getEventDispatcher()->listen('eloquent.retrieved: '.Deployment::class, function () use ($placement): void {
            DB::table('deployments')->where('id', $placement->id)->update(['ends' => '2027-06-30']);
        });

        try {
            app(RemoveEmployee::class)->handle($employee);
            $this->fail('Expected the removal to abort.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('changed while this employee was being removed', $e->getMessage());
        }

        // Nothing half-done: the employee is still here and the placement is
        // untouched. It reads as its original date rather than the injected
        // one because the injected write shares this transaction and rolls
        // back with it — which is the property, not an artefact: the action
        // leaves the world exactly as it found it.
        $this->assertNotSoftDeleted($employee);
        $this->assertSame('2026-12-31', $placement->fresh()->ends->toDateString());
    }

    /**
     * The other half of the same hole, and the one the compare-and-swap
     * cannot see: a deployment **inserted** after this action's SELECT is not
     * in the set it iterates at all, and READ COMMITTED gives the transaction
     * no reason to notice. Only the postcondition check immediately before
     * the soft delete catches it.
     *
     * Without that check the employee would be removed while holding an open
     * placement nobody closed — the exact visibility state under decision 30
     * that removal exists to end.
     */
    public function test_a_placement_opened_mid_removal_aborts_the_whole_removal(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 00:30:00'));
        // Fixed-term deliberately: an *open* placement covers 2027 as well,
        // so the row inserted below would collide with it on
        // deployments_no_overlap and this test would prove that constraint
        // instead of the postcondition.
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => '2026-12-31']);
        $this->withTenant(Agency::findOrFail($placement->agency_id));
        $employee = $placement->employee;
        $inserted = (string) Str::ulid();

        // Fires while the action is loading its set, so the new row lands
        // after the SELECT that would have included it. Guarded to once, and
        // dated clear of the existing placement so deployments_no_overlap
        // does not refuse it instead.
        $once = false;
        Deployment::getEventDispatcher()->listen('eloquent.retrieved: '.Deployment::class, function () use (&$once, $placement, $inserted): void {
            if ($once) {
                return;
            }

            $once = true;

            DB::table('deployments')->insert([
                'id' => $inserted,
                'agency_id' => $placement->agency_id,
                'employee_id' => $placement->employee_id,
                'workgroup_id' => $placement->workgroup_id,
                'parent_id' => null,
                'starts' => '2027-01-01',
                'ends' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            app(RemoveEmployee::class)->handle($employee);
            $this->fail('Expected the removal to abort.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('opened while this employee was being removed', $e->getMessage());
        }

        $this->assertNotSoftDeleted($employee);
        $this->assertSame('2026-12-31', $placement->fresh()->ends->toDateString(), 'the close rolled back with everything else');
    }

    /**
     * Both fixtures inject on the employee delete, which is the last write
     * the action makes, so anything it did to `deployments` first must be
     * rolled back with it.
     *
     * `eloquent.updated: Deployment` used to be the first fixture here and
     * cannot be any more: closing a placement is an expected-value
     * query-builder UPDATE — `WHERE ends IS NOT DISTINCT FROM :expected`, so
     * a concurrent close is not overwritten (decision 30 makes these ranges
     * access control) — and a query-builder update fires no model events at
     * all. Failing *after* the close proves the same property without
     * needing one.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function failurePoints(): array
    {
        return [
            'started placement is closed' => ['eloquent.deleted: '.Employee::class, '2024-01-01'],
            'future placement is deleted' => ['eloquent.deleted: '.Employee::class, '2026-09-11'],
        ];
    }

    /**
     * Throw after an actual write, not before it: otherwise preserving the
     * original state could pass even with no transaction. No test-owned
     * savepoint wraps the call; removing the action transaction leaves the
     * employee deleted and the placement closed or gone.
     */
    #[DataProvider('failurePoints')]
    public function test_a_failed_removal_rolls_back_both_records(string $event, string $starts): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 00:30:00'));
        $placement = Deployment::factory()->create(['starts' => $starts]);
        $this->withTenant(Agency::findOrFail($placement->agency_id));
        $employee = $placement->employee;
        $dispatcher = Employee::getEventDispatcher();
        $isolated = clone $dispatcher;
        Employee::setEventDispatcher($isolated);
        $isolated->listen($event, function (): void {
            throw new RuntimeException('Removal write failed.');
        });

        try {
            try {
                app(RemoveEmployee::class)->handle($employee);
                $this->fail('Expected the injected write failure.');
            } catch (RuntimeException $e) {
                $this->assertSame('Removal write failed.', $e->getMessage());
            }
        } finally {
            Employee::setEventDispatcher($dispatcher);
        }

        $this->assertNotSoftDeleted($employee);
        $this->assertDatabaseHas('deployments', ['id' => $placement->id, 'ends' => null]);
    }
}
