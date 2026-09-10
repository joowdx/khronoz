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
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.id', $user->agency_id));
    }

    public function test_platform_user_defaults_to_the_platform_agency(): void
    {
        Agency::factory()->count(2)->create();

        $this->actingAs(User::factory()->platform()->create())->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.platform', true)->has('agencies', 2));
    }

    public function test_platform_user_enters_the_agency_held_in_session(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs(User::factory()->platform()->create())->withSession(['agency' => $agency->id])->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.id', $agency->id));
    }

    /**
     * The other half of the Organization nav gate (OrganizationNavContractTest
     * holds the component's side of it). `app-sidebar.tsx` decides whether to
     * render Workgroups and Employees from the shared `agency` prop, so what this
     * has to prove is that the prop tells the truth in both cases: a superuser
     * who has entered nothing is on the platform tenant, where a workgroup or an
     * employee cannot exist at all (agency_not_platform, P0001), and one who
     * has entered an agency is not.
     */
    public function test_the_shared_agency_prop_distinguishes_the_platform_tenant_from_an_entered_one(): void
    {
        $agency = Agency::factory()->create();
        $superuser = User::factory()->platform()->create();

        $this->actingAs($superuser)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.platform', true)
                ->where('auth.user.platform', true));

        $this->actingAs($superuser)->withSession(['agency' => $agency->id])->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.platform', false)
                ->where('agency.id', $agency->id)
                ->where('auth.user.platform', true));
    }

    /** A staff user is never on the platform tenant, so the group is theirs whenever the permission is. */
    public function test_a_staff_user_is_always_inside_a_real_agency(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.platform', false));
    }

    public function test_agency_user_ignores_a_session_agency(): void
    {
        $user = User::factory()->create();
        $other = Agency::factory()->create();

        $this->actingAs($user)->withSession(['agency' => $other->id])->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.id', $user->agency_id));
    }
}
