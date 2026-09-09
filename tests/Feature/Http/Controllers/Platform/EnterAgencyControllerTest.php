<?php

namespace Tests\Feature\Http\Controllers\Platform;

use App\Models\Agency;
use App\Models\User;
use Tests\TestCase;

class EnterAgencyControllerTest extends TestCase
{
    public function test_enter_stores_the_agency_in_the_session_and_leave_clears_it(): void
    {
        $agency = Agency::factory()->create();
        $superuser = User::factory()->platform()->create();

        $this->actingAs($superuser)->post(route('platform.agencies.enter', $agency))
            ->assertRedirect(route('dashboard'))->assertSessionHas('agency', $agency->id);

        $this->actingAs($superuser)->delete(route('platform.agencies.leave'))
            ->assertRedirect(route('dashboard'))->assertSessionMissing('agency');
    }

    public function test_entering_the_platform_row_is_not_possible_by_id(): void
    {
        $this->actingAs(User::factory()->platform()->create())
            ->post(route('platform.agencies.enter', Agency::platform()->id))->assertNotFound();
    }
}
