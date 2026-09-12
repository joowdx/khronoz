<?php

namespace Tests\Feature\Http\Controllers\Settings;

use App\Models\Agency;
use App\Models\Passkey;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use Tests\Support\Authenticator;
use Tests\TestCase;

class PasskeyTest extends TestCase
{
    private function confirm(User $user): void
    {
        $this->actingAs($user)->withSession(['auth.confirmed_user' => $user->id, 'auth.password_confirmed_at' => now()->timestamp]);
    }

    private function enroll(User $user, ?Authenticator $device = null): Authenticator
    {
        $device ??= new Authenticator;
        $this->confirm($user);
        $options = $this->getJson(route('settings.passkeys.options'))->assertOk()->json('options');
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->postJson(route('settings.passkeys.store'), ['name' => 'Phone', 'credential' => $device->registration($options)])->assertSuccessful();

        return $device;
    }

    public function test_enrollment_listing_rename_and_removal_use_the_owning_account(): void
    {
        $entered = Agency::factory()->create();
        $owner = $this->actingAsPlatform($entered);
        $this->enroll($owner);
        $passkey = app(Tenant::class)->within($owner->agency, fn () => $owner->passkeys()->sole());
        $this->assertSame($owner->agency_id, $passkey->agency_id);
        $this->get(route('settings.security.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('agency.id', $entered->id)->where('passkeys.0.name', 'Phone')->missing('passkeys.0.credential')->missing('passkeys.0.credential_id'));
        $this->patch(route('settings.passkeys.update', $passkey->id), ['name' => 'My phone'])->assertSessionHasNoErrors();
        $this->assertSame('My phone', $passkey->fresh()->name);
        $this->delete(route('settings.passkeys.destroy', $passkey->id))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
    }

    public function test_a_verified_passkey_signs_in_directly_with_two_factor_enabled(): void
    {
        $user = User::factory()->acceptedLegal()->create();
        $device = $this->enroll($user);
        $user->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        $this->post(route('logout'));
        $options = $this->getJson(route('passkeys.login.options'))->assertOk()->json('options');
        $this->assertSame('required', $options['userVerification']);
        $this->postJson(route('passkeys.login.store'), ['credential' => $device->assertion($options, $user)])
            ->assertOk()->assertJson(['redirect' => route('dashboard')])->assertSessionMissing('login');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(DB::table('passkeys')->where('user_id', $user->id)->value('last_used_at'));
    }

    public function test_assertions_reject_wrong_origins_missing_verification_bad_signatures_and_replays(): void
    {
        $user = User::factory()->acceptedLegal()->create();
        $device = $this->enroll($user);
        $this->post(route('logout'));
        foreach (['origin', 'verification', 'signature', 'challenge'] as $failure) {
            $options = $this->getJson(route('passkeys.login.options'))->json('options');
            $credential = $device->assertion($options, $user, $failure === 'origin' ? 'https://attacker.test' : null, $failure !== 'verification');
            if ($failure === 'signature') {
                $credential['response']['signature'] = 'd3Jvbmc';
            }
            if ($failure === 'challenge') {
                $this->getJson(route('passkeys.login.options'));
            }
            $this->postJson(route('passkeys.login.store'), ['credential' => $credential])->assertUnprocessable();
            $this->assertGuest();
        }
        $this->travel(61)->seconds();
        $options = $this->getJson(route('passkeys.login.options'))->json('options');
        $credential = $device->assertion($options, $user);
        $this->postJson(route('passkeys.login.store'), ['credential' => $credential])->assertOk();
        $this->post(route('logout'));
        $this->postJson(route('passkeys.login.store'), ['credential' => $credential])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_registration_requires_a_recent_confirmation_and_valid_origin_and_verification(): void
    {
        $user = User::factory()->acceptedLegal()->create();
        $this->actingAs($user)->getJson(route('settings.passkeys.options'))->assertStatus(423);
        $this->confirm($user);
        foreach ([false, true] as $verified) {
            $options = $this->getJson(route('settings.passkeys.options'))->json('options');
            $credential = (new Authenticator)->registration($options, $verified ? 'https://attacker.test' : null, $verified);
            $this->postJson(route('settings.passkeys.store'), ['name' => 'Phone', 'credential' => $credential])->assertUnprocessable();
        }
        $this->assertDatabaseCount('passkeys', 0);
    }

    public function test_challenges_expire_and_cannot_be_used_for_another_purpose(): void
    {
        $user = User::factory()->acceptedLegal()->create();
        $device = $this->enroll($user);
        $options = $this->getJson(route('passkeys.confirm.options'))->json('options');
        $this->travel(61)->seconds();
        $this->postJson(route('passkeys.confirm.store'), ['credential' => $device->assertion($options, $user)])->assertUnprocessable();
        $this->post(route('logout'));
        $this->postJson(route('passkeys.login.store'), ['credential' => $device->assertion($options, $user)])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_passkey_reauthentication_requires_the_same_account_and_preserves_entered_agency(): void
    {
        $first = User::factory()->acceptedLegal()->create();
        $device = $this->enroll($first);
        $this->post(route('logout'));
        $entered = Agency::factory()->create();
        $owner = $this->actingAsPlatform($entered);
        $ownDevice = $this->enroll($owner);
        session()->forget(['auth.confirmed_user', 'auth.password_confirmed_at']);
        $options = $this->getJson(route('passkeys.confirm.options'))->json('options');
        $this->postJson(route('passkeys.confirm.store'), ['credential' => $device->assertion($options, $first)])->assertUnprocessable();
        $options = $this->getJson(route('passkeys.confirm.options'))->json('options');
        $this->postJson(route('passkeys.confirm.store'), ['credential' => $ownDevice->assertion($options, $owner)])->assertOk();
        $this->assertSame($owner->id, session('auth.confirmed_user'));
        $this->assertSame($entered->id, session('agency'));
    }

    public function test_other_users_passkeys_cannot_be_renamed_or_removed(): void
    {
        $owner = User::factory()->acceptedLegal()->create();
        $other = Passkey::factory()->create();
        $this->confirm($owner);
        $this->patch(route('settings.passkeys.update', $other->id), ['name' => 'Stolen'])->assertNotFound();
        $this->delete(route('settings.passkeys.destroy', $other->id))->assertNotFound();
        $this->assertDatabaseHas('passkeys', ['id' => $other->id]);
    }

    public function test_database_rejects_duplicate_credentials_and_mismatched_agencies(): void
    {
        $passkey = Passkey::factory()->create();
        $this->assertDatabaseRefuses('23505', fn () => Passkey::factory()->create(['credential_id' => $passkey->credential_id]));
        $this->assertDatabaseRefuses('23503', fn () => Passkey::factory()->create(['user_id' => $passkey->user_id, 'agency_id' => Agency::factory()->create()->id]));
    }

    public function test_passkeys_cannot_bypass_invitation_activation_or_legal_acknowledgment(): void
    {
        $user = User::factory()->acceptedLegal()->create();
        $device = $this->enroll($user);
        $this->post(route('logout'));
        $user->forceFill(['invited_at' => now(), 'email_verified_at' => null])->save();
        $options = $this->getJson(route('passkeys.login.options'))->json('options');
        $this->postJson(route('passkeys.login.store'), ['credential' => $device->assertion($options, $user)])->assertUnprocessable();
        $this->assertGuest();
        $user->forceFill(['email_verified_at' => now()])->save();
        $options = $this->getJson(route('passkeys.login.options'))->json('options');
        $this->postJson(route('passkeys.login.store'), ['credential' => $device->assertion($options, $user)])->assertOk();
        $this->app['env'] = 'production';
        $this->get(route('dashboard'))->assertRedirect(route('legal.acceptance.create'));
    }
}
