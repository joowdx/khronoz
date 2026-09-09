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
        User::factory()->forAgency($user->agency)->invited()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')
                ->where('counts.users', 4)
                ->where('counts.active', 3)
                ->where('counts.invited', 1));
    }

    /**
     * A bare User::query()->count() would count every user of every agency;
     * this only fails if the counts stop going through the tenant's own agency
     * relation (User carries no tenant scope — app/Models/User.php).
     */
    public function test_dashboard_counts_only_the_current_agencys_users(): void
    {
        $user = User::factory()->create();
        User::factory()->forAgency($user->agency)->count(2)->create();
        User::factory()->forAgency($user->agency)->invited()->create();

        $other = Agency::factory()->create();
        User::factory()->forAgency($other)->count(5)->create();
        User::factory()->forAgency($other)->invited()->count(4)->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.users', 4)
                ->where('counts.active', 3)
                ->where('counts.invited', 1));
    }

    /**
     * The platform figures: how many agencies exist, how many users they hold
     * between them, and how many of them nobody can sign in to yet. The
     * platform agency's own users are the superusers and are counted as
     * `counts.users`, not folded into `agency_users`.
     */
    public function test_dashboard_shows_the_platform_figures_for_the_platform_tenant(): void
    {
        $staffed = Agency::factory()->create();
        User::factory()->forAgency($staffed)->count(3)->create();
        Agency::factory()->count(2)->create();

        $this->actingAsPlatform();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')
                ->where('counts.agencies', 3)
                ->where('counts.agency_users', 3)
                ->where('counts.empty_agencies', 2)
                ->where('counts.users', 1));
    }

    public function test_dashboard_omits_the_platform_figures_for_an_ordinary_agency_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('counts.agencies')
                ->missing('counts.agency_users')
                ->missing('counts.empty_agencies'));
    }

    /**
     * Once a platform user has entered a specific agency, $tenant->agency() is
     * that agency, not the platform row, so the platform figures must drop and
     * the people figures must become that agency's — the check is the tenant's
     * own platform flag, not the user's.
     */
    public function test_dashboard_omits_the_platform_figures_once_a_platform_user_has_entered_an_agency(): void
    {
        $agency = Agency::factory()->create();
        User::factory()->forAgency($agency)->count(2)->create();
        $this->actingAsPlatform(enter: $agency);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('counts.agencies')
                ->where('counts.users', 2));
    }

    /**
     * The needs-attention rows are driven entirely by their counts, and the
     * page renders a row only when its count is non-zero. Nothing outstanding
     * must therefore arrive as a zero, not as an absent key, or the page
     * cannot tell "nothing to do" from "not measured".
     */
    public function test_a_tenant_with_nothing_outstanding_reports_zero_rather_than_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.invited', 0));
    }

    public function test_an_agency_whose_users_have_all_signed_in_reports_no_empty_agencies(): void
    {
        $staffed = Agency::factory()->create();
        User::factory()->forAgency($staffed)->create();

        $this->actingAsPlatform();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.empty_agencies', 0));
    }

    /**
     * An invited user has not verified their address, so they are neither
     * active nor able to reach this page. Both halves matter: `active` must not
     * count them, and `invited` must stop counting them the moment they accept.
     */
    public function test_accepting_an_invitation_moves_a_user_from_invited_to_active(): void
    {
        $user = User::factory()->create();
        $invitee = User::factory()->forAgency($user->agency)->invited()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.active', 1)
                ->where('counts.invited', 1));

        $invitee->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.active', 2)
                ->where('counts.invited', 0));
    }
}
