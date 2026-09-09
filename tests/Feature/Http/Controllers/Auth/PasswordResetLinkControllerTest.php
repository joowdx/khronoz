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

    /**
     * This response must be indistinguishable from the known-email case
     * above: same redirect target, same flash key, same literal text. That
     * is the whole point — the HTTP response can no longer be used to test
     * whether an address is registered. Notification::assertNothingSent()
     * is what proves the real behaviour still differs server-side; do not
     * "simplify" these two tests back into asserting different responses,
     * or the endpoint becomes enumerable again.
     */
    public function test_unregistered_email_gets_the_same_response_as_a_registered_one(): void
    {
        Notification::fake([ResetPassword::class]);

        $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@agency.gov.ph'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', 'If that address is registered, a reset link is on its way.');

        Notification::assertNothingSent();
    }
}
