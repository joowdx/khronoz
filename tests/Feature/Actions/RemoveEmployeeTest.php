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
    /** @return array<string, array{0: string}> */
    public static function failurePoints(): array
    {
        return ['placement write' => ['eloquent.updated: '.Deployment::class], 'employee delete' => ['eloquent.deleted: '.Employee::class]];
    }

    /**
     * Throw after an actual write, not before it: otherwise preserving the
     * original state could pass even with no transaction. No test-owned
     * savepoint wraps the call; removing the action transaction leaves the
     * placement changed (and, in the second case, the employee deleted).
     */
    #[DataProvider('failurePoints')]
    public function test_a_failed_removal_rolls_back_both_records(string $event): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 00:30:00'));
        $placement = Deployment::factory()->create(['starts' => '2024-01-01']);
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
        $this->assertNull($placement->fresh()->ends);
    }
}
