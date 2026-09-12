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
        Notification::fake([ResetPassword::class]);
        $user = User::factory()->create();

        $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', 'If that address is registered, a reset link is on its way.');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unregistered_email_gets_the_same_response_as_a_registered_one(): void
    {
        Notification::fake([ResetPassword::class]);

        $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@agency.gov.ph'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', 'If that address is registered, a reset link is on its way.');

        Notification::assertNothingSent();
    }
}
