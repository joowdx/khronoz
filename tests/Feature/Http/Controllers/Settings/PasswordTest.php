<?php

namespace Tests\Feature\Http\Controllers\Settings;

use App\Models\Agency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    public function test_confirmation_rejects_wrong_password_and_unsafe_redirects(): void
    {
        $user = $this->actingAsAgency(Agency::factory()->create());
        $this->post(route('password.confirm.store'), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertFalse(session()->has('auth.confirmed_user'));
        $this->post(route('password.confirm.store'), ['password' => 'password', 'destination' => '//evil.test'])
            ->assertRedirect('/settings/security')->assertSessionHas('auth.confirmed_user', $user->id);
    }

    public function test_password_changes_keep_this_session_and_invalidate_other_sessions_and_pending_email(): void
    {
        $user = $this->actingAsAgency(Agency::factory()->create());
        $this->post(route('password.confirm.store'), ['password' => 'password']);
        $oldRemember = $user->remember_token;
        $oldHash = $user->password;
        $session = session()->getId();
        DB::table('sessions')->insert([
            ['id' => $session, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'another-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ]);
        $user->forceFill(['pending_email' => 'new@example.test', 'pending_email_token' => str_repeat('a', 64), 'pending_email_expires_at' => now()->addHour()])->save();
        $this->put(route('settings.password.update'), ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertSessionHasNoErrors()->assertRedirect(route('settings.security.edit'));
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertNotSame($oldRemember, $user->fresh()->remember_token);
        $this->assertNull($user->fresh()->pending_email);
        $this->assertDatabaseMissing('sessions', ['id' => 'another-device']);
        $this->assertAuthenticatedAs($user);
        $this->get(route('settings.security.edit'))->assertOk();
        $this->withSession(['password_hash_web' => $oldHash]);
        $this->get(route('settings.profile.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_password_validation_and_expired_confirmation_leave_the_password_unchanged(): void
    {
        $user = $this->actingAsAgency(Agency::factory()->create());
        $this->post(route('password.confirm.store'), ['password' => 'password']);
        $this->put(route('settings.password.update'), ['password' => 'new-password', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(5)->timestamp]);
        $this->put(route('settings.password.update'), ['password' => 'new-password', 'password_confirmation' => 'new-password'])
            ->assertRedirectContains('/confirm-password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
