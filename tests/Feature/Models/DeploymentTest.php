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

        // A reassignment overlapping the substantive placement it departs
        // from: accepted, and this assertion is what makes the three above
        // mean anything. All of them insert rows with a null parent_id, so
        // they pass unchanged against one *total* exclusion constraint —
        // without this line the test would keep its name while no longer
        // covering the partition that decision 31 turns on.
        $reassignment = Deployment::factory()->under($accepted)->create([
            'workgroup_id' => $workgroupB->id,
            'starts' => '2026-02-01',
            'ends' => '2026-02-28',
        ]);
        $this->assertDatabaseHas('deployments', ['id' => $reassignment->id, 'parent_id' => $accepted->id]);
    }

    /**
     * deployments_no_overlapping_movements — the other half of the partition.
     * Two open reassignments always overlap (a null `ends` is unbounded), and
     * endpoint-adjacent ones do too, because daterange(...,'[]') includes
     * both ends. Nobody is detailed to two places at once.
     */
    public function test_reassignments_for_one_employee_cannot_overlap(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $elsewhere = Workgroup::factory()->create(['agency_id' => $placement->agency_id]);

        Deployment::factory()->under($placement)->create([
            'workgroup_id' => $elsewhere->id,
            'starts' => '2026-03-01',
            'ends' => null,
        ]);

        // A second open reassignment under the same placement.
        $this->assertDatabaseRefuses('23P01', fn () => Deployment::factory()->under($placement)->create([
            'workgroup_id' => $elsewhere->id,
            'starts' => '2026-06-01',
            'ends' => null,
        ]));

        DB::table('deployments')->whereNotNull('parent_id')->update(['ends' => '2026-03-31']);

        // The day the first one ended is still occupied by it.
        $this->assertDatabaseRefuses('23P01', fn () => Deployment::factory()->under($placement)->create([
            'workgroup_id' => $elsewhere->id,
            'starts' => '2026-03-31',
            'ends' => '2026-04-30',
        ]));
    }

    /**
     * deployments_parent_not_self, both INSERT and UPDATE. 23514 and not
     * P0001: the CHECK is evaluated before deployments_nested's AFTER trigger
     * runs, the same way workgroups_parent_not_self pre-empts
     * workgroups_acyclic (confirmed against the live schema, not reasoned
     * about). The trigger would catch a self-parent row too — it finds the
     * row as its own child — so this constraint is the declarative shortcut,
     * not the only guard.
     */
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

    /**
     * deployments_parent_id_employee_id_foreign, insert side: a parent that
     * is a real deployment of a real workgroup in the same agency, but of a
     * *different employee*. That is the whole reason `UNIQUE (id,
     * employee_id)` exists — "the parent is the same employee" is structural,
     * not a trigger.
     *
     * 23503 and not P0001 because deployments_nested() looks its parent up on
     * the pair (id, employee_id) and stays silent when it finds nothing,
     * exactly as agency_not_platform() does for a nonexistent agency. Were
     * the lookup on id alone, the trigger would find this row and raise
     * P0001 on containment first, and this FK's insert side would have no
     * reachable violation at all.
     */
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

    /**
     * deployments_parent_id_employee_id_foreign, delete side — the constraint
     * that makes decision 35's delete-as-correction safe. A placement with a
     * reassignment nested under it cannot be deleted until the reassignment
     * goes first.
     *
     * A raw DELETE is not defensive here, it is the real code path:
     * `Deployment` has no SoftDeletes and must never acquire it (decision 35),
     * precisely so that a correction actually removes the row from the
     * timeline the exclusion constraints index.
     */
    public function test_a_placement_with_a_reassignment_cannot_be_deleted(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        Deployment::factory()->under($placement)->create(['starts' => '2026-03-01', 'ends' => '2026-03-31']);

        $this->assertDatabaseRefuses('23001', fn () => DB::table('deployments')->where('id', $placement->id)->delete());
    }

    /**
     * deployments_id_employee_id_unique (Ruling P4, the same reasoning as
     * `(id, agency_id)` above): nothing can violate the pair without
     * duplicating `id`, which the primary key refuses with the identical
     * 23505, so a refusal test could not isolate it. Assert its existence in
     * the catalog instead — it is load-bearing as the parent FK's target, and
     * dropping it would silently take the "same employee" guarantee with it.
     */
    public function test_id_and_employee_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'deployments_id_employee_id_unique'"));
    }

    /**
     * deployments_nested, all four limbs (P0001). Two rules — a reassignment
     * sits inside the placement it departs from, and its parent is itself
     * substantive — each written from both directions, because containment is
     * breakable from the side the other does not watch.
     *
     * Every construction here is chosen to get *past* the exclusion
     * constraints, which are checked at index insertion and therefore
     * pre-empt an AFTER trigger. Without that care the assertions would be
     * testing deployments_no_overlapping_movements while claiming to test the
     * trigger.
     *
     * MEASURED, by neutralising one RAISE at a time and re-running this test:
     *
     * | limb | | |
     * | --- | --- | --- |
     * | 3 | child inside parent's range | **covered** — this test fails without it |
     * | 4 | parent's range still holds its children | **covered** — this test fails without it |
     * | 1 | parent must be substantive | redundant; see below |
     * | 2 | a placement with children cannot become a movement | redundant; see below |
     *
     * Limbs 1 and 2 have no reachable violation, and that is a property of
     * the schema rather than a gap in this test. "No reassignment under a
     * reassignment" *follows* from the other two rules: nesting requires
     * containment (limbs 3 and 4), two movements of one employee that contain
     * one another necessarily overlap, and overlapping movements are refused
     * by deployments_no_overlapping_movements with 23P01 before the trigger
     * is consulted. Every attempt to reach limb 1 or 2 is therefore answered
     * by 23P01 or by limb 3/4 first. They stay in the function as depth — the
     * same bargain workgroups strikes by keeping both
     * workgroups_parent_not_self and workgroups_acyclic — and the two
     * assertions below still drive their code paths; they just cannot isolate
     * them.
     */
    public function test_a_reassignment_must_nest_inside_a_substantive_placement(): void
    {
        // Limb 3, child side: the range reaches past its parent's end.
        $fixedTerm = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => '2026-06-30']);

        $this->assertDatabaseRefuses('P0001', fn () => Deployment::factory()->under($fixedTerm)->create([
            'starts' => '2026-06-01',
            'ends' => '2026-07-31',
        ]));

        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $reassignment = Deployment::factory()->under($placement)->create(['starts' => '2026-03-01', 'ends' => '2026-03-31']);

        // Limb 1, child side: no reassignment under a reassignment. The June
        // range is disjoint from the March one on purpose — a *contained*
        // child would trip deployments_no_overlapping_movements first and
        // this assertion would be testing that constraint instead.
        $this->assertDatabaseRefuses('P0001', fn () => Deployment::factory()->under($reassignment)->create([
            'starts' => '2026-06-01',
            'ends' => '2026-06-30',
        ]));

        // Limb 4, parent side: closing the placement early would strand the
        // reassignment outside it. This is the limb that binds
        // TransferEmployee and RemoveEmployee, both of which close the open
        // placement, and it is why enforcing containment on the child alone
        // is not enough.
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')
            ->where('id', $placement->id)
            ->update(['ends' => '2026-02-28']));

        // Limb 2, parent side: a placement with a reassignment under it
        // cannot itself become one. Reachable only by repointing it *and*
        // moving its range clear of its own child in the same statement —
        // repointing alone leaves parent and child overlapping as two
        // reassignments, which deployments_no_overlapping_movements refuses
        // with 23P01 before the trigger is consulted.
        // Close the placement before opening the later one: two open
        // substantive rows for one employee is exactly what
        // deployments_no_overlap refuses, so the setup would fail on its own
        // constraint. 2027-12-31 still contains the March 2026 reassignment,
        // so limb 4 lets this through.
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
