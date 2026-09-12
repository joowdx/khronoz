<?php

namespace Tests\Feature\Http\Resources;

use App\Http\Resources\DeploymentResource;
use App\Models\Deployment;
use Tests\TestCase;

class DeploymentResourceTest extends TestCase
{
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

    public function test_a_reassignment_is_distinguishable_from_a_placement_on_the_wire(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $reassignment = Deployment::factory()->under($placement)->create(['starts' => '2026-03-01', 'ends' => '2026-03-31']);

        $this->assertNull(DeploymentResource::make($placement)->resolve()['parent_id']);
        $this->assertSame($placement->id, DeploymentResource::make($reassignment)->resolve()['parent_id']);
    }
}
