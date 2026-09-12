<?php

namespace Tests\Feature\Actions;

use App\Actions\TransferEmployee;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TransferEmployeeTest extends TestCase
{
    public function test_opens_the_first_deployment_for_an_employee_with_none(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $deployment = app(TransferEmployee::class)->handle($employee, $workgroup, Carbon::parse('2026-01-01'));

        $this->assertSame($workgroup->id, $deployment->workgroup_id);
        $this->assertSame($employee->id, $deployment->employee_id);
        $this->assertSame($agency->id, $deployment->agency_id);
        $this->assertSame('2026-01-01', $deployment->starts->toDateString());
        $this->assertNull($deployment->ends);
        $this->assertSame(1, Deployment::query()->where('employee_id', $employee->id)->count());
    }

    public function test_closes_the_open_deployment_the_day_before_the_new_one_starts(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroupA = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $workgroupB = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $original = Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroupA->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $new = app(TransferEmployee::class)->handle($employee->fresh(), $workgroupB, Carbon::parse('2026-03-15'));

        $this->assertSame('2026-03-14', $original->fresh()->ends->toDateString());
        $this->assertSame($workgroupB->id, $new->workgroup_id);
        $this->assertSame('2026-03-15', $new->starts->toDateString());
        $this->assertNull($new->ends);
    }

    public function test_refuses_an_overlap(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroupA = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $workgroupB = Workgroup::factory()->create(['agency_id' => $agency->id]);

        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroupA->id,
            'starts' => '2025-06-01',
            'ends' => '2025-09-01',
        ]);

        $this->assertDatabaseRefuses('23P01', fn () => app(TransferEmployee::class)->handle($employee->fresh(), $workgroupB, Carbon::parse('2025-07-01')));

        $this->assertDatabaseHas('deployments', ['employee_id' => $employee->id, 'starts' => '2025-06-01', 'ends' => '2025-09-01']);
    }

    public function test_a_failed_open_rolls_back_the_close(): void
    {
        $foreignWorkgroup = Workgroup::factory()->create(); // a different, unrelated agency

        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroupA = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $current = Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroupA->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        try {
            app(TransferEmployee::class)->handle($employee->fresh(), $foreignWorkgroup, Carbon::parse('2026-06-01'));
            $this->fail('expected the paired FK to refuse a workgroup from another agency');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }

        $this->assertNull($current->fresh()->ends);
    }
}
/** @return void */
