<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailVerificationPromptControllerTest extends TestCase
{
    public function test_notice_page_renders_for_an_unverified_user(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('verification.notice'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/verify-email'));
    }

    public function test_already_verified_user_is_redirected_to_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('verification.notice'))->assertRedirect(route('dashboard'));
    }

    public function test_unverified_user_visiting_the_dashboard_is_redirected_to_the_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }
}
