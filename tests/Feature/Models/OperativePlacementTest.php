<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Suspension;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * 05-calendar.md rule 3 and decision 31's third reading, from both sides:
 * `Employee::operativeDeployment()` for one person, and `Suspension::appliesTo()`
 * for the set.
 *
 * The rule is **not** "every employee deployed under the workgroup". During a
 * detail an employee has two rows covering the date — the substantive
 * placement stays open because the plantilla item never left — so that
 * wording would let a closure declared on the mother office excuse a day the
 * person actually worked in the receiving one, and would fail to excuse the
 * day they really were closed out of.
 */
class OperativePlacementTest extends TestCase
{
    private CarbonImmutable $date;

    private Agency $agency;

    private Workgroup $mother;

    private Workgroup $receiving;

    protected function setUp(): void
    {
        parent::setUp();

        $this->date = CarbonImmutable::parse('2026-09-15');
        $this->agency = Agency::factory()->create();
        $this->mother = Workgroup::factory()->create(['agency_id' => $this->agency->id]);
        $this->receiving = Workgroup::factory()->create(['agency_id' => $this->agency->id]);
        $this->withTenant($this->agency);
    }

    /** An employee placed in $workgroup for the whole of 2026. */
    private function placedIn(Workgroup $workgroup): Deployment
    {
        return Deployment::factory()->create([
            'agency_id' => $this->agency->id,
            'workgroup_id' => $workgroup->id,
            'employee_id' => Employee::factory()->create(['agency_id' => $this->agency->id])->id,
            'starts' => '2026-01-01',
            'ends' => '2026-12-31',
        ]);
    }

    /** A detail of $placement's employee into $workgroup, for September. */
    private function detailedTo(Deployment $placement, Workgroup $workgroup, string $starts = '2026-09-01', string $ends = '2026-09-30'): Deployment
    {
        return Deployment::factory()->under($placement)->create([
            'workgroup_id' => $workgroup->id,
            'starts' => $starts,
            'ends' => $ends,
        ]);
    }

    public function test_with_no_movement_the_operative_row_is_the_placement(): void
    {
        $placement = $this->placedIn($this->mother);

        $this->assertSame($placement->id, $placement->employee->operativeDeployment($this->date)?->id);
    }

    /** The whole point: during a detail, the movement is where the person is. */
    public function test_a_movement_covering_the_date_is_the_operative_row(): void
    {
        $placement = $this->placedIn($this->mother);
        $movement = $this->detailedTo($placement, $this->receiving);

        $operative = $placement->employee->operativeDeployment($this->date);

        $this->assertSame($movement->id, $operative?->id);
        $this->assertSame($this->receiving->id, $operative?->workgroup_id);
    }

    /**
     * And it reverts. A detail that ended in August leaves September to the
     * substantive placement — which is why the accessor takes a date rather
     * than answering "is there an open movement".
     */
    public function test_a_movement_that_does_not_cover_the_date_is_ignored(): void
    {
        $placement = $this->placedIn($this->mother);
        $this->detailedTo($placement, $this->receiving, '2026-08-01', '2026-08-31');

        $operative = $placement->employee->operativeDeployment($this->date);

        $this->assertSame($placement->id, $operative?->id);
        $this->assertSame($this->mother->id, $operative?->workgroup_id);
    }

    public function test_an_employee_with_no_deployment_on_the_date_has_no_operative_row(): void
    {
        $placement = $this->placedIn($this->mother);

        $this->assertNull($placement->employee->operativeDeployment(CarbonImmutable::parse('2025-06-01')));
    }

    /**
     * **The test rule 3 exists for.** The mother office closes; the employee
     * detailed out of it was not there, worked in the receiving office, and
     * must not be excused.
     */
    public function test_a_closure_of_the_mother_office_does_not_excuse_someone_detailed_out_of_it(): void
    {
        $placement = $this->placedIn($this->mother);
        $this->detailedTo($placement, $this->receiving);
        $stayed = $this->placedIn($this->mother);

        $reached = Suspension::factory()->forWorkgroup($this->mother)
            ->create(['date' => $this->date])
            ->appliesTo()->pluck('id')->all();

        $this->assertContains($stayed->employee_id, $reached, 'the colleague who stayed is excused');
        $this->assertNotContains($placement->employee_id, $reached, 'the one detailed out is not');
    }

    /** The other half: the receiving office closes, and the detailed employee is excused. */
    public function test_a_closure_of_the_receiving_office_excuses_someone_detailed_into_it(): void
    {
        $placement = $this->placedIn($this->mother);
        $this->detailedTo($placement, $this->receiving);

        $reached = Suspension::factory()->forWorkgroup($this->receiving)
            ->create(['date' => $this->date])
            ->appliesTo()->pluck('id')->all();

        $this->assertContains($placement->employee_id, $reached);
    }

    /**
     * And the same closure once the detail has ended: the person is back under
     * the mother office, so the receiving office's closure no longer reaches
     * them. Same fixture, a date outside the detail.
     */
    public function test_the_reach_follows_the_date_not_the_existence_of_a_detail(): void
    {
        $placement = $this->placedIn($this->mother);
        $this->detailedTo($placement, $this->receiving, '2026-08-01', '2026-08-31');

        $reached = Suspension::factory()->forWorkgroup($this->receiving)
            ->create(['date' => $this->date])
            ->appliesTo()->pluck('id')->all();

        $this->assertNotContains($placement->employee_id, $reached, 'the detail was over by September');
    }

    /** A suspension on a workgroup cascades to its descendants (rule 3). */
    public function test_a_closure_cascades_to_descendant_workgroups(): void
    {
        $division = Workgroup::factory()->create(['agency_id' => $this->agency->id, 'parent_id' => $this->mother->id]);
        $section = Workgroup::factory()->create(['agency_id' => $this->agency->id, 'parent_id' => $division->id]);
        $deep = $this->placedIn($section);
        $elsewhere = $this->placedIn($this->receiving);

        $reached = Suspension::factory()->forWorkgroup($this->mother)
            ->create(['date' => $this->date])
            ->appliesTo()->pluck('id')->all();

        $this->assertContains($deep->employee_id, $reached, 'two levels down is still under it');
        $this->assertNotContains($elsewhere->employee_id, $reached);
    }

    /** Agency-wide: everyone deployed on the date, and nobody who is not. */
    public function test_an_agency_wide_closure_reaches_everyone_deployed_that_day(): void
    {
        $here = $this->placedIn($this->mother);
        $alsoHere = $this->placedIn($this->receiving);
        $gone = Deployment::factory()->create([
            'agency_id' => $this->agency->id,
            'workgroup_id' => $this->mother->id,
            'employee_id' => Employee::factory()->create(['agency_id' => $this->agency->id])->id,
            'starts' => '2024-01-01',
            'ends' => '2024-12-31',
        ]);

        $reached = Suspension::factory()
            ->create(['agency_id' => $this->agency->id, 'date' => $this->date])
            ->appliesTo()->pluck('id')->all();

        $this->assertContains($here->employee_id, $reached);
        $this->assertContains($alsoHere->employee_id, $reached);
        $this->assertNotContains($gone->employee_id, $reached, 'their placement ended in 2024');
    }

    /**
     * A detailed employee is still reached by an agency-wide closure of their
     * own agency: the operative distinction only matters when a workgroup is
     * named.
     *
     * **Depth, not a guard** — stated honestly because mutation testing said
     * so. No formulation of the agency-wide clause can currently fail this.
     * `deployments_nested` requires a movement's range to sit inside its
     * parent's, so whenever a movement covers a date the substantive
     * placement covers it too: "any deployment covering the date", "the
     * placement covering the date" and "the movement or the placement" are
     * the same set of employees. The test records the intent, which stops
     * someone narrowing the clause to movements only — a change the *other*
     * agency-wide test does kill.
     */
    public function test_an_agency_wide_closure_still_reaches_a_detailed_employee(): void
    {
        $placement = $this->placedIn($this->mother);
        $this->detailedTo($placement, $this->receiving);

        $reached = Suspension::factory()
            ->create(['agency_id' => $this->agency->id, 'date' => $this->date])
            ->appliesTo()->pluck('id')->all();

        $this->assertContains($placement->employee_id, $reached);
    }

    /**
     * Another agency's employees are never reached, agency-wide or not.
     *
     * The foreign fixture is built with that agency as the tenant and the
     * tenant then restored, because BelongsToAgency refuses an explicit
     * agency_id that disagrees with a set tenant — setUp() has already set
     * ours, so the usual "build the foreign row first" order is not available
     * here.
     */
    public function test_a_closure_never_reaches_another_agencys_employees(): void
    {
        $mine = $this->placedIn($this->mother);

        $other = Agency::factory()->create();
        $this->withTenant($other);
        $theirs = Deployment::factory()->create([
            'agency_id' => $other->id,
            'starts' => '2026-01-01',
            'ends' => '2026-12-31',
        ]);
        $this->withTenant($this->agency);

        $reached = Suspension::factory()
            ->create(['agency_id' => $this->agency->id, 'date' => $this->date])
            ->appliesTo()->pluck('id')->all();

        $this->assertContains($mine->employee_id, $reached);
        $this->assertNotContains($theirs->employee_id, $reached);
    }
}
