<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Agency;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SetTenantTest extends TestCase
{
    public function test_agency_user_gets_their_own_agency(): void
    {
        $user = User::factory()->acceptedLegal()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.id', $user->agency_id));
    }

    public function test_platform_user_defaults_to_the_platform_agency(): void
    {
        Agency::factory()->count(2)->create();

        $this->actingAs(User::factory()->acceptedLegal()->platform()->create())->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.platform', true)->has('agencies', 2));
    }

    public function test_platform_user_enters_the_agency_held_in_session(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs(User::factory()->acceptedLegal()->platform()->create())->withSession(['agency' => $agency->id])->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.id', $agency->id));
    }

    public function test_the_shared_agency_prop_distinguishes_the_platform_tenant_from_an_entered_one(): void
    {
        $agency = Agency::factory()->create();
        $superuser = User::factory()->acceptedLegal()->platform()->create();

        $this->actingAs($superuser)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.platform', true)
                ->where('auth.user.platform', true));

        $this->actingAs($superuser)->withSession(['agency' => $agency->id])->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.platform', false)
                ->where('agency.id', $agency->id)
                ->where('auth.user.platform', true));
    }

    public function test_a_staff_user_is_always_inside_a_real_agency(): void
    {
        $this->actingAs(User::factory()->acceptedLegal()->create())->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.platform', false));
    }

    public function test_agency_user_ignores_a_session_agency(): void
    {
        $user = User::factory()->acceptedLegal()->create();
        $other = Agency::factory()->create();

        $this->actingAs($user)->withSession(['agency' => $other->id])->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.id', $user->agency_id));
    }
}
