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
    public function test_a_placement_changing_mid_removal_aborts_the_whole_removal(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 00:30:00'));
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => '2026-12-31']);
        $this->withTenant(Agency::findOrFail($placement->agency_id));
        $employee = $placement->employee;

        Deployment::getEventDispatcher()->listen('eloquent.retrieved: '.Deployment::class, function () use ($placement): void {
            DB::table('deployments')->where('id', $placement->id)->update(['ends' => '2027-06-30']);
        });

        try {
            app(RemoveEmployee::class)->handle($employee);
            $this->fail('Expected the removal to abort.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('changed while this employee was being removed', $e->getMessage());
        }

        $this->assertNotSoftDeleted($employee);
        $this->assertSame('2026-12-31', $placement->fresh()->ends->toDateString());
    }

    public function test_a_placement_opened_mid_removal_aborts_the_whole_removal(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 00:30:00'));
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => '2026-12-31']);
        $this->withTenant(Agency::findOrFail($placement->agency_id));
        $employee = $placement->employee;
        $inserted = (string) Str::ulid();

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

    /** @return array<string, array{0: string, 1: string}> */
    public static function failurePoints(): array
    {
        return [
            'started placement is closed' => ['eloquent.deleted: '.Employee::class, '2024-01-01'],
            'future placement is deleted' => ['eloquent.deleted: '.Employee::class, '2026-09-11'],
        ];
    }

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
