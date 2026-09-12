<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use Tests\TestCase;

class ShiftControllerTest extends TestCase
{
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

    public function test_viewing_requires_scheduling_view_permission(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewCalendar);

        $this->get(route('shifts.index'))->assertForbidden();
    }
}
