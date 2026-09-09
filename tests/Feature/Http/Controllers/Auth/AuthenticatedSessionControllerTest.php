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
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest();
    }

    public function test_uninvited_or_unaccepted_users_cannot_log_in(): void
    {
        $user = User::factory()->invited()->create();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
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
