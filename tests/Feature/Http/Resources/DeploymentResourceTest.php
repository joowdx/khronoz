<?php

namespace Tests\Feature\Http\Resources;

use App\Http\Resources\DeploymentResource;
use App\Models\Deployment;
use Tests\TestCase;

class DeploymentResourceTest extends TestCase
{
    /**
     * Important 3: starts/ends are `date`-cast columns — same defect and fix
     * as EmployeeResource's birthdate (see that test's
     * docblock). json_encode/decode round-trips the resource the same way
     * the real HTTP response does, so this pins the literal wire value.
     */
    public function test_date_only_columns_cross_the_wire_as_plain_date_strings(): void
    {
        $deployment = Deployment::factory()->create([
            'starts' => '2026-03-01',
            'ends' => '2026-06-30',
        ]);

        $wire = json_decode(json_encode(DeploymentResource::make($deployment)->resolve()), true);

        $this->assertSame('2026-03-01', $wire['starts']);
        $this->assertSame('2026-06-30', $wire['ends']);
    }

    /**
     * `parent_id` is the only thing on the wire that distinguishes a
     * reassignment from a substantive placement — there is no type field
     * (decision 31) — and the employee profile indents the history table by
     * it. Null on a placement, the parent's id on a reassignment: assert both
     * halves, since a resource that always sent null would satisfy either one
     * alone.
     */
    public function test_a_reassignment_is_distinguishable_from_a_placement_on_the_wire(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $reassignment = Deployment::factory()->under($placement)->create(['starts' => '2026-03-01', 'ends' => '2026-03-31']);

        $this->assertNull(DeploymentResource::make($placement)->resolve()['parent_id']);
        $this->assertSame($placement->id, DeploymentResource::make($reassignment)->resolve()['parent_id']);
    }
}
