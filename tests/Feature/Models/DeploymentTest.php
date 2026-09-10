<?php

namespace Tests\Feature\Models;

use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * deployments_agency_id_foreign is deliberately untested on both sides here
 * (Ruling P5). To violate it, agency_id must name no agency — but the two
 * paired FKs require (employee_id, agency_id) and (workgroup_id, agency_id) to
 * match real rows, whose own agency_id is valid, so no row exists where this
 * FK fails while the pairs hold: any 23503 caught could come from either
 * pair and would prove nothing about this one. Its delete side is covered
 * transitively by the workgroups and employees agency-delete tests (a deployment
 * can only exist under an agency that still exists).
 */
class DeploymentTest extends TestCase
{
    /**
     * agency_id NOT NULL. employee_id/workgroup_id are real (if cross-agency)
     * rows, not omitted or nonexistent — MATCH SIMPLE skips both paired FKs
     * once agency_id itself is null, so the only possible refusal is this
     * NOT NULL, unambiguously.
     */
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

    /**
     * deployments_id_agency_id_unique (Ruling P4): nothing on this table
     * references the pair, so the primary key would raise the identical
     * 23505 and a refusal test could not isolate it. Assert its existence in
     * the catalog instead.
     */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'deployments_id_agency_id_unique'"));
    }

    /** deployments_employee_id_agency_id_foreign, insert side: an employee of a different agency. */
    public function test_employee_id_must_share_the_deployments_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Deployment::factory()->create(['employee_id' => $employee->id]));
    }

    /**
     * deployments_employee_id_agency_id_foreign, delete side. A raw DELETE,
     * not $employee->delete() — employees are soft deleted, so the Eloquent
     * call is an UPDATE the FK never sees.
     */
    public function test_employee_with_a_deployment_cannot_be_hard_deleted(): void
    {
        $deployment = Deployment::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $deployment->employee_id)->delete());
    }

    /** deployments_workgroup_id_agency_id_foreign, insert side: a workgroup of a different agency. */
    public function test_workgroup_id_must_share_the_deployments_agency(): void
    {
        $workgroup = Workgroup::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Deployment::factory()->create(['workgroup_id' => $workgroup->id]));
    }

    /** deployments_workgroup_id_agency_id_foreign, delete side: a workgroup that still has a deployment. */
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

        // Ending the same day it started: accepted.
        $sameDay = Deployment::factory()->create(['starts' => '2026-01-10', 'ends' => '2026-01-10']);
        $this->assertDatabaseHas('deployments', ['id' => $sameDay->id]);
    }

    /**
     * deployments_no_overlap. Two assertions: two open ranges for one
     * employee always overlap (a null `ends` is an unbounded upper bound,
     * which is how "at most one open deployment" is enforced for free), and
     * endpoint-adjacent rows overlap too, because daterange(...,'[]') is
     * inclusive of both ends rather than Postgres's default '[)'. A third
     * assertion confirms the same range is fine for a different employee.
     */
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

        // A second open deployment for the same employee: always overlaps.
        $this->assertDatabaseRefuses('23P01', fn () => Deployment::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroupB->id,
            'starts' => '2026-02-01',
            'ends' => null,
        ]));

        // Close the first deployment, then try to open the next on the exact day it ended.
        DB::table('deployments')->where('employee_id', $employee->id)->update(['ends' => '2026-01-31']);

        $this->assertDatabaseRefuses('23P01', fn () => Deployment::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroupB->id,
            'starts' => '2026-01-31',
            'ends' => null,
        ]));

        // The identical range, a different employee: accepted.
        $other = Employee::factory()->create(['agency_id' => $agency]);
        $accepted = Deployment::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $other->id,
            'workgroup_id' => $workgroupA->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);
        $this->assertDatabaseHas('deployments', ['id' => $accepted->id]);
    }

    /**
     * starts is NOT NULL: a null value makes deployments_dates_ordered
     * evaluate to NULL and pass, and still yields a daterange with an
     * infinite lower bound that deployments_no_overlap indexes happily.
     */
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

    /**
     * employee_id is NOT NULL. MATCH SIMPLE, the FK default, skips the check
     * entirely when a referencing column is null, so a null employee_id
     * would bypass deployments_employee_id_agency_id_foreign — the
     * paired-FK tenancy guarantee (docs/design/07-constraints.md:10-22)
     * evaporates for that row. null = null also evaluates to NULL, and an
     * exclusion constraint needs true to conflict, so a null employee_id
     * would escape deployments_no_overlap too, allowing unlimited
     * overlapping open rows. One nullable column would silently defeat two
     * constraints with the whole suite green.
     */
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

    /**
     * workgroup_id is NOT NULL, the same FK-half of the reason above: MATCH
     * SIMPLE would skip deployments_workgroup_id_agency_id_foreign entirely once
     * workgroup_id itself is null, bypassing the paired-FK tenancy guarantee
     * (docs/design/07-constraints.md:10-22) for that row.
     */
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
