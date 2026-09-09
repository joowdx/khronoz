<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VerifyEmailControllerTest extends TestCase
{
    public function test_valid_signed_url_verifies_the_email_and_redirects_to_dashboard(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($url)->assertRedirect(route('dashboard'))->assertSessionHas('success', 'Email verified.');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_already_verified_user_keeps_their_original_verification_timestamp(): void
    {
        $user = User::factory()->create();
        $verifiedAt = $user->email_verified_at;
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($url)->assertRedirect(route('dashboard'));

        $this->assertTrue($verifiedAt->equalTo($user->fresh()->email_verified_at));
    }

    public function test_unsigned_url_returns_403(): void
    {
        $user = User::factory()->unverified()->create();
        $url = route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_seventh_verification_attempt_in_a_minute_is_throttled(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);

        foreach (range(1, 6) as $i) {
            $this->actingAs($user)->get($url);
        }

        $this->actingAs($user)->get($url)->assertStatus(429);
    }
}
