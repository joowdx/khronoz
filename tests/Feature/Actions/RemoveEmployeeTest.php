<?php

namespace Tests\Feature\Actions;

use App\Actions\RemoveEmployee;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RemoveEmployeeTest extends TestCase
{
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
