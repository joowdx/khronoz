<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Suspension;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
use Tests\TestCase;

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

    public function test_a_movement_covering_the_date_is_the_operative_row(): void
    {
        $placement = $this->placedIn($this->mother);
        $movement = $this->detailedTo($placement, $this->receiving);

        $operative = $placement->employee->operativeDeployment($this->date);

        $this->assertSame($movement->id, $operative?->id);
        $this->assertSame($this->receiving->id, $operative?->workgroup_id);
    }

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

    public function test_a_closure_of_the_receiving_office_excuses_someone_detailed_into_it(): void
    {
        $placement = $this->placedIn($this->mother);
        $this->detailedTo($placement, $this->receiving);

        $reached = Suspension::factory()->forWorkgroup($this->receiving)
            ->create(['date' => $this->date])
            ->appliesTo()->pluck('id')->all();

        $this->assertContains($placement->employee_id, $reached);
    }

    public function test_the_reach_follows_the_date_not_the_existence_of_a_detail(): void
    {
        $placement = $this->placedIn($this->mother);
        $this->detailedTo($placement, $this->receiving, '2026-08-01', '2026-08-31');

        $reached = Suspension::factory()->forWorkgroup($this->receiving)
            ->create(['date' => $this->date])
            ->appliesTo()->pluck('id')->all();

        $this->assertNotContains($placement->employee_id, $reached, 'the detail was over by September');
    }

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

    public function test_an_agency_wide_closure_still_reaches_a_detailed_employee(): void
    {
        $placement = $this->placedIn($this->mother);
        $this->detailedTo($placement, $this->receiving);

        $reached = Suspension::factory()
            ->create(['agency_id' => $this->agency->id, 'date' => $this->date])
            ->appliesTo()->pluck('id')->all();

        $this->assertContains($placement->employee_id, $reached);
    }

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
