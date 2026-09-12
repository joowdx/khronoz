<?php

namespace Tests\Feature\Models;

use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeploymentTest extends TestCase
{
    public function test_deployment_needs_an_agency(): void
    {
        $employee = Employee::factory()->create();
        $workgroup = Workgroup::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('deployments')->insert([
            'id' => (string) Str::ulid(),
            'agency_id' => null,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroup->id,
            'starts' => '2026-01-01',
            'ends' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'deployments_id_agency_id_unique'"));
    }

    public function test_employee_id_must_share_the_deployments_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Deployment::factory()->create(['employee_id' => $employee->id]));
    }

    public function test_employee_with_a_deployment_cannot_be_hard_deleted(): void
    {
        $deployment = Deployment::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $deployment->employee_id)->delete());
    }

    public function test_workgroup_id_must_share_the_deployments_agency(): void
    {
        $workgroup = Workgroup::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Deployment::factory()->create(['workgroup_id' => $workgroup->id]));
    }

    public function test_workgroup_with_a_deployment_cannot_be_deleted(): void
    {
        $deployment = Deployment::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('workgroups')->where('id', $deployment->workgroup_id)->delete());
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => Deployment::factory()->create([
            'starts' => '2026-01-10',
            'ends' => '2026-01-09',
        ]));

        $sameDay = Deployment::factory()->create(['starts' => '2026-01-10', 'ends' => '2026-01-10']);
        $this->assertDatabaseHas('deployments', ['id' => $sameDay->id]);
    }

    public function test_closed_ends_on_or_after_a_start_date_the_caller_overrides(): void
    {
        $starts = CarbonImmutable::today()->addYears(5)->toDateString();

        $deployment = Deployment::factory()->closed()->create(['starts' => $starts]);

        $this->assertGreaterThanOrEqual($starts, $deployment->ends->toDateString());
    }

    public function test_closed_ends_before_today_when_it_started_in_the_past(): void
    {
        $deployment = Deployment::factory()->closed()->create(['starts' => '2020-03-01']);

        $this->assertGreaterThanOrEqual('2020-03-01', $deployment->ends->toDateString());
        $this->assertLessThanOrEqual(CarbonImmutable::yesterday()->toDateString(), $deployment->ends->toDateString());
    }

    public function test_deployments_for_one_employee_cannot_overlap(): void
    {
        $employee = Employee::factory()->create();
        $agency = $employee->agency_id;
        $workgroupA = Workgroup::factory()->create(['agency_id' => $agency]);
        $workgroupB = Workgroup::factory()->create(['agency_id' => $agency]);

        Deployment::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroupA->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('23P01', fn () => Deployment::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroupB->id,
            'starts' => '2026-02-01',
            'ends' => null,
        ]));

        DB::table('deployments')->where('employee_id', $employee->id)->update(['ends' => '2026-01-31']);

        $this->assertDatabaseRefuses('23P01', fn () => Deployment::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroupB->id,
            'starts' => '2026-01-31',
            'ends' => null,
        ]));

        $other = Employee::factory()->create(['agency_id' => $agency]);
        $accepted = Deployment::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $other->id,
            'workgroup_id' => $workgroupA->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);
        $this->assertDatabaseHas('deployments', ['id' => $accepted->id]);

        $reassignment = Deployment::factory()->under($accepted)->create([
            'workgroup_id' => $workgroupB->id,
            'starts' => '2026-02-01',
            'ends' => '2026-02-28',
        ]);
        $this->assertDatabaseHas('deployments', ['id' => $reassignment->id, 'parent_id' => $accepted->id]);
    }

    public function test_reassignments_for_one_employee_cannot_overlap(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $elsewhere = Workgroup::factory()->create(['agency_id' => $placement->agency_id]);

        Deployment::factory()->under($placement)->create([
            'workgroup_id' => $elsewhere->id,
            'starts' => '2026-03-01',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('23P01', fn () => Deployment::factory()->under($placement)->create([
            'workgroup_id' => $elsewhere->id,
            'starts' => '2026-06-01',
            'ends' => null,
        ]));

        DB::table('deployments')->whereNotNull('parent_id')->update(['ends' => '2026-03-31']);

        $this->assertDatabaseRefuses('23P01', fn () => Deployment::factory()->under($placement)->create([
            'workgroup_id' => $elsewhere->id,
            'starts' => '2026-03-31',
            'ends' => '2026-04-30',
        ]));
    }

    public function test_a_deployment_cannot_be_its_own_parent(): void
    {
        $employee = Employee::factory()->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $employee->agency_id]);
        $id = (string) Str::ulid();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('deployments')->insert([
            'id' => $id,
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroup->id,
            'parent_id' => $id,
            'starts' => '2026-01-01',
            'ends' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $deployment = Deployment::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('deployments')
            ->where('id', $deployment->id)
            ->update(['parent_id' => $deployment->id]));
    }

    public function test_parent_must_be_a_deployment_of_the_same_employee(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $other = Employee::factory()->create(['agency_id' => $placement->agency_id]);

        $this->assertDatabaseRefuses('23503', fn () => Deployment::factory()->create([
            'agency_id' => $placement->agency_id,
            'employee_id' => $other->id,
            'workgroup_id' => $placement->workgroup_id,
            'parent_id' => $placement->id,
            'starts' => '2026-03-01',
            'ends' => '2026-03-31',
        ]));
    }

    public function test_a_placement_with_a_reassignment_cannot_be_deleted(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        Deployment::factory()->under($placement)->create(['starts' => '2026-03-01', 'ends' => '2026-03-31']);

        $this->assertDatabaseRefuses('23001', fn () => DB::table('deployments')->where('id', $placement->id)->delete());
    }

    public function test_id_and_employee_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'deployments_id_employee_id_unique'"));
    }

    public function test_a_reassignment_must_nest_inside_a_substantive_placement(): void
    {
        $fixedTerm = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => '2026-06-30']);

        $this->assertDatabaseRefuses('P0001', fn () => Deployment::factory()->under($fixedTerm)->create([
            'starts' => '2026-06-01',
            'ends' => '2026-07-31',
        ]));

        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $reassignment = Deployment::factory()->under($placement)->create(['starts' => '2026-03-01', 'ends' => '2026-03-31']);

        $this->assertDatabaseRefuses('P0001', fn () => Deployment::factory()->under($reassignment)->create([
            'starts' => '2026-06-01',
            'ends' => '2026-06-30',
        ]));

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')
            ->where('id', $placement->id)
            ->update(['ends' => '2026-02-28']));

        DB::table('deployments')->where('id', $placement->id)->update(['ends' => '2027-12-31']);
        $later = Deployment::factory()->create([
            'agency_id' => $placement->agency_id,
            'employee_id' => $placement->employee_id,
            'workgroup_id' => $placement->workgroup_id,
            'starts' => '2028-01-01',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')
            ->where('id', $placement->id)
            ->update([
                'parent_id' => $later->id,
                'starts' => '2028-02-01',
                'ends' => '2028-02-28',
            ]));
    }

    public function test_starts_is_required(): void
    {
        $employee = Employee::factory()->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $employee->agency_id]);

        $this->assertDatabaseRefuses('23502', fn () => DB::table('deployments')->insert([
            'id' => (string) Str::ulid(),
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroup->id,
            'starts' => null,
            'ends' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_employee_id_is_required(): void
    {
        $employee = Employee::factory()->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $employee->agency_id]);

        $this->assertDatabaseRefuses('23502', fn () => DB::table('deployments')->insert([
            'id' => (string) Str::ulid(),
            'agency_id' => $employee->agency_id,
            'employee_id' => null,
            'workgroup_id' => $workgroup->id,
            'starts' => '2026-01-01',
            'ends' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_workgroup_id_is_required(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('deployments')->insert([
            'id' => (string) Str::ulid(),
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
            'workgroup_id' => null,
            'starts' => '2026-01-01',
            'ends' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
/** @return void */
