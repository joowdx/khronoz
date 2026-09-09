<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailVerificationNotificationControllerTest extends TestCase
{
    public function test_sends_a_new_verification_email_to_an_unverified_user(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHas('status', 'A new verification link has been sent to your email address.');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_already_verified_user_is_redirected_without_sending(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('verification.send'))->assertRedirect(route('dashboard'));

        Notification::assertNothingSent();
    }

    public function test_seventh_resend_in_a_minute_is_throttled(): void
    {
        $user = User::factory()->unverified()->create();
        foreach (range(1, 6) as $i) {
            $this->actingAs($user)->post(route('verification.send'));
        }

        $this->actingAs($user)->post(route('verification.send'))->assertStatus(429);
    }
}
