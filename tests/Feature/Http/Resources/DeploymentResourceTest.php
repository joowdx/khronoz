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
}
