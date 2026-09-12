<?php

namespace Tests\Feature\Http\Controllers\Settings;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    private function account(bool $enabled = true): User
    {
        $user = User::factory()->acceptedLegal()->create();
        if ($enabled) {
            $user->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one', 'recovery-two'])),
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        return $user;
    }

    private function confirmSession(User $user): void
    {
        $this->actingAs($user)->withSession(['auth.confirmed_user' => $user->id, 'auth.password_confirmed_at' => now()->timestamp]);
    }

    private function code(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret));
    }

    public function test_setup_is_pending_until_a_valid_code_and_secrets_are_not_page_props(): void
    {
        $user = $this->account(false);
        $this->confirmSession($user);
        $this->post(route('settings.two-factor.store'))->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->getJson(route('settings.two-factor.show'))->assertOk()->assertJsonStructure(['secret', 'qr'])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('settings.security.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('twoFactor.pending', true)->where('twoFactor.enabled', false)->missing('twoFactor.secret')->missing('auth.user.two_factor_secret'));
        $this->post(route('settings.two-factor.confirm'), ['code' => 'wrong'])->assertSessionHasErrors('code');
        $this->post(route('settings.two-factor.confirm'), ['code' => $this->code($user)])->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertStringNotContainsString($this->code($user), $user->fresh()->two_factor_secret);
        $this->getJson(route('settings.two-factor.show'))->assertNotFound();
        $this->getJson(route('settings.recovery-codes.index'))->assertJsonCount(8, 'codes');
    }

    public function test_password_login_waits_for_the_second_factor(): void
    {
        $user = $this->account();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password', 'remember' => true])
            ->assertRedirect(route('two-factor.login'))->assertSessionHas('login.remember', true);
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->post(route('two-factor.login.store'), ['code' => $this->code($user)])
            ->assertRedirect(route('dashboard'))->assertSessionMissing('login');
        $this->assertAuthenticatedAs($user);
    }

    public function test_recovery_codes_can_be_consumed_only_once(): void
    {
        $user = $this->account();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one'])->assertSessionHasNoErrors();
        $this->assertSame(['recovery-two'], $user->fresh()->recoveryCodes());
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one'])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-two'])->assertSessionHasNoErrors();
        $this->assertSame([], $user->fresh()->recoveryCodes());
    }

    public function test_expired_and_missing_challenges_cannot_sign_in(): void
    {
        $user = $this->account();
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one'])->assertSessionHasErrors('form');
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $this->travel(5)->minutes();
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one'])->assertSessionHasErrors('form')->assertSessionMissing('login');
        $this->assertGuest();
        $this->assertSame(['recovery-one', 'recovery-two'], $user->fresh()->recoveryCodes());
    }

    public function test_password_reset_preserves_two_factor_and_invalidates_the_pending_login(): void
    {
        $user = $this->account();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $token = Password::createToken($user);
        $this->post(route('password.store'), ['email' => $user->email, 'token' => $token, 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one'])->assertSessionHasErrors('form');
        $this->assertGuest();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'replacement-password'])->assertRedirect(route('two-factor.login'));
    }

    public function test_reauthentication_requires_password_and_second_factor_and_does_not_flash_secrets(): void
    {
        $user = $this->account();
        $this->actingAs($user);
        $this->post(route('password.confirm.store'), ['password' => 'password'])->assertSessionHasErrors('code');
        $this->assertFalse(session()->has('auth.confirmed_user'));
        $this->post(route('password.confirm.store'), ['password' => 'wrong', 'code' => '123456', 'recovery_code' => 'private-code'])
            ->assertSessionHasErrors('password');
        $this->assertArrayNotHasKey('password', session()->getOldInput());
        $this->assertArrayNotHasKey('code', session()->getOldInput());
        $this->assertArrayNotHasKey('recovery_code', session()->getOldInput());
        $this->post(route('password.confirm.store'), ['password' => 'password', 'code' => $this->code($user)])->assertSessionHasNoErrors();
        $this->assertSame($user->id, session('auth.confirmed_user'));
    }

    public function test_recovery_regeneration_and_removal_require_recent_identity_confirmation(): void
    {
        $user = $this->account();
        $this->actingAs($user);
        $this->getJson(route('settings.recovery-codes.index'))->assertStatus(423);
        $this->postJson(route('settings.recovery-codes.store'))->assertStatus(423);
        $this->deleteJson(route('settings.two-factor.destroy'))->assertStatus(423);
        $this->confirmSession($user);
        $this->post(route('settings.recovery-codes.store'))->assertSessionHasNoErrors();
        $this->assertNotContains('recovery-one', $user->fresh()->recoveryCodes());
        $this->delete(route('settings.two-factor.destroy'))->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_recovery_codes);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_platform_management_never_mutates_another_account(): void
    {
        $other = $this->account();
        $agency = Agency::factory()->create();
        $owner = $this->actingAsPlatform($agency);
        $this->confirmSession($owner);
        $this->post(route('settings.two-factor.store'), ['user_id' => $other->id])->assertSessionHasNoErrors();
        $this->delete(route('settings.two-factor.destroy'), ['user_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertTrue($other->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertNull($owner->fresh()->two_factor_secret);
        $this->get(route('settings.security.edit'))->assertInertia(fn (Assert $page) => $page->where('agency.id', $agency->id));
    }

    public function test_challenge_attempts_are_throttled_and_no_public_registration_is_added(): void
    {
        $user = $this->account();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        foreach (range(1, 6) as $attempt) {
            $this->post(route('two-factor.login.store'), ['recovery_code' => 'wrong'])->assertSessionHasErrors('recovery_code');
        }
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'wrong'])->assertStatus(429);
        $this->assertGuest();
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }
}
