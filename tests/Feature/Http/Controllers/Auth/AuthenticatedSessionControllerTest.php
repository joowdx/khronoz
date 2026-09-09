<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticatedSessionControllerTest extends TestCase
{
    public function test_login_page_renders(): void
    {
        $this->get(route('login'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/login'));
    }

    public function test_valid_credentials_start_a_session_and_redirect_to_dashboard(): void
    {
        $user = User::factory()->create();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_returns_the_generic_error(): void
    {
        $user = User::factory()->create();

        $this->from(route('login'))->post(route('login'), ['email' => $user->email, 'password' => 'nope'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['form' => 'That email and password do not match. Check both and try again, or reset your password.']);
        $this->assertGuest();
    }

    /**
     * Pins the generic-failure invariant: LoginRequest::authenticate() has a
     * single unbranched failure path today, so an unregistered email and a
     * wrong password already produce the same message. Nothing stops a
     * future refactor from splitting them into two branches that leak which
     * case happened — this test fails the moment that split appears.
     */
    public function test_unregistered_email_returns_the_same_generic_error_as_a_wrong_password(): void
    {
        $this->from(route('login'))->post(route('login'), ['email' => 'nobody@agency.gov.ph', 'password' => 'nope'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['form' => 'That email and password do not match. Check both and try again, or reset your password.']);
        $this->assertGuest();
    }

    public function test_uninvited_or_unaccepted_users_cannot_log_in(): void
    {
        $user = User::factory()->invited()->create();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['form' => 'This invitation has not been accepted yet. Use the link in your email, or ask your HR office to send it again.']);
        $this->assertGuest();
    }

    public function test_sixth_attempt_in_a_minute_is_throttled(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'nope']);
        }

        $this->post(route('login'), ['email' => $user->email, 'password' => 'nope'])->assertStatus(429);
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }
}
