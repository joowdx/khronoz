<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordResetLinkControllerTest extends TestCase
{
    public function test_request_page_renders(): void
    {
        $this->get(route('password.request'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/forgot-password'));
    }

    public function test_known_email_sends_a_reset_link_notification(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', 'We have emailed your password reset link.');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unregistered_email_returns_a_validation_error(): void
    {
        Notification::fake();

        $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@agency.gov.ph'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors(['email' => "We can't find a user with that email address."]);

        Notification::assertNothingSent();
    }
}
