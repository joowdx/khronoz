<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InviteControllerTest extends TestCase
{
    public function test_signed_link_renders_accept_invite_with_the_users_name_and_email(): void
    {
        $user = User::factory()->invited()->create();
        $url = URL::temporarySignedRoute('invite.accept', now()->addDays(7), ['user' => $user]);

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/accept-invite')
            ->where('user.name', $user->name)
            ->where('user.email', $user->email)
            ->where('action', $url));
    }

    public function test_expired_signature_returns_403(): void
    {
        $user = User::factory()->invited()->create();
        $url = URL::temporarySignedRoute('invite.accept', now()->subDay(), ['user' => $user]);

        $this->get($url)->assertForbidden();
    }

    public function test_unsigned_url_returns_403(): void
    {
        $user = User::factory()->invited()->create();

        $this->get(route('invite.accept', $user))->assertForbidden();
    }

    public function test_already_accepted_invite_redirects_to_login_with_an_error(): void
    {
        $user = User::factory()->create(); // default state: already verified, i.e. already accepted
        $url = URL::temporarySignedRoute('invite.accept', now()->addDays(7), ['user' => $user]);

        $this->get($url)->assertRedirect(route('login'))->assertSessionHas('error', 'This invitation was already accepted. Sign in instead.');
    }

    public function test_valid_password_accepts_the_invite_logs_in_and_redirects_to_dashboard(): void
    {
        $user = User::factory()->invited()->create();
        $url = URL::temporarySignedRoute('invite.store', now()->addDays(7), ['user' => $user]);

        $this->post($url, [
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertRedirect(route('dashboard'))->assertSessionHas('success', 'Welcome, '.$user->name.'.');

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue(Hash::check('a-new-password', $user->fresh()->password));
    }

    public function test_password_confirmation_mismatch_returns_a_validation_error(): void
    {
        $user = User::factory()->invited()->create();
        $url = URL::temporarySignedRoute('invite.store', now()->addDays(7), ['user' => $user]);

        $this->post($url, [
            'password' => 'a-new-password',
            'password_confirmation' => 'does-not-match',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    /**
     * The signature on an invite link never expires the *business* state it
     * was minted for: create() already refuses an accepted invite, but the
     * signed POST is a separate route the browser never visits directly, so
     * store() must repeat the same check or a still-validly-signed link a
     * user has already used once could replay and overwrite their password.
     */
    public function test_store_refuses_an_already_accepted_invite(): void
    {
        $user = User::factory()->create(); // already verified, i.e. already accepted
        $originalPassword = $user->password;
        $url = URL::temporarySignedRoute('invite.store', now()->addDays(7), ['user' => $user]);

        $this->post($url, [
            'password' => 'attacker-chosen-password',
            'password_confirmation' => 'attacker-chosen-password',
        ])->assertRedirect(route('login'))->assertSessionHas('error', 'This invitation was already accepted. Sign in instead.');

        $this->assertGuest();
        $this->assertSame($originalPassword, $user->fresh()->password);
    }
}
