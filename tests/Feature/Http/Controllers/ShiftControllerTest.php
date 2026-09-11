<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Shift;
use Tests\TestCase;

class ShiftControllerTest extends TestCase
{
    /**
     * A permitted user creates a working shift; the row lands in `shifts` and
     * the response redirects to the index.
     *
     * This is a smoke test only — a later hardening wave covers tenant scoping,
     * prop shape, refusal translation and full validation coverage.
     */
    public function test_a_permitted_user_can_create_a_working_shift(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageScheduling);

        $this->post(route('shifts.store'), [
            'name' => 'Standard 8–5',
            'slots' => [
                [
                    'in' => '08:00',
                    'out' => '12:00',
                    'grace' => 0,
                    'window' => [-240, 180],
                ],
                [
                    'in' => '13:00',
                    'out' => '17:00',
                    'grace' => 0,
                    'window' => [-120, 300],
                ],
            ],
            'required' => 480,
            'flex' => 0,
            'color' => 1,
            'remote' => false,
            'trust' => false,
        ])->assertRedirect(route('shifts.index'));

        $this->assertDatabaseHas('shifts', [
            'agency_id' => $agency->id,
            'name' => 'Standard 8–5',
            'required' => 480,
        ]);
    }

    /**
     * A user without the scheduling view permission gets 403 on shifts.index.
     *
     * `Gate::authorize('viewAny', Shift::class)` resolves through
     * ShiftPolicy::viewAny, which requires `scheduling.view`. Passing an
     * unrelated permission proves the gate is the actual boundary.
     */
    public function test_viewing_requires_scheduling_view_permission(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewCalendar);

        $this->get(route('shifts.index'))->assertForbidden();
    }
}
