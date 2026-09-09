<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Agency;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_shows_the_current_agency_counts(): void
    {
        $user = User::factory()->create();
        User::factory()->forAgency($user->agency)->count(2)->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')->where('counts.users', 3));
    }

    /**
     * A bare User::query()->count() would count every user of every agency;
     * this only fails if counts.users stops going through the tenant's own
     * agency relation (User carries no tenant scope — app/Models/User.php).
     */
    public function test_dashboard_counts_only_the_current_agencys_users(): void
    {
        $user = User::factory()->create();
        User::factory()->forAgency($user->agency)->count(2)->create();
        User::factory()->count(5)->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.users', 3));
    }

    public function test_dashboard_shows_the_agency_count_for_the_platform_tenant(): void
    {
        Agency::factory()->count(2)->create();
        $this->actingAsPlatform();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.agencies', 2));
    }

    /**
     * Once a platform user has entered a specific agency, $tenant->agency()
     * is that agency, not the platform row, so counts.agencies must drop —
     * the check is the tenant's own platform flag, not the user's.
     */
    public function test_dashboard_omits_the_agency_count_once_a_platform_user_has_entered_an_agency(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsPlatform(enter: $agency);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->missing('counts.agencies'));
    }

    public function test_dashboard_omits_the_agency_count_for_an_ordinary_agency_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->missing('counts.agencies'));
    }
}
