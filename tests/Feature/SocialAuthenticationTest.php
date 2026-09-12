<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Identity;
use App\Models\User;
use App\Support\SocialProvider;
use App\Tenancy\Tenant;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Facades\Socialite;
use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SocialAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['google', 'apple'] as $provider) {
            config(["services.$provider.client_id" => 'client-id', "services.$provider.client_secret" => 'client-secret', "services.$provider.redirect" => url("auth/$provider/callback")]);
        }
    }

    private function confirm(User $user): void
    {
        $this->actingAs($user)->withSession(['auth.confirmed_user' => $user->id, 'auth.password_confirmed_at' => now()->timestamp]);
    }

    private function begin(string $provider = 'google', bool $link = false): string
    {
        $response = $link ? $this->post(route('settings.connections.store', $provider)) : $this->get(route('social.redirect', $provider));
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(session('oauth.state'), $query['state']);
        $this->assertSame(session('oauth.nonce'), $query['nonce']);

        return $query['state'];
    }

    private function google(string $subject, ?string $email = null): void
    {
        Socialite::driver('google')->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'private-access', 'refresh_token' => 'private-refresh'])),
            new Response(200, [], json_encode(['sub' => $subject, 'email' => $email, 'picture' => 'private-avatar'])),
        ]))]));
    }

    private function googleCallback(string $state): TestResponse
    {
        return $this->get(route('social.google.callback', ['state' => $state, 'code' => 'authorization-code']));
    }

    public static function providerCombinations(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    #[DataProvider('providerCombinations')]
    public function test_login_exposes_only_configured_provider_flags(bool $google, bool $apple): void
    {
        if (! $google) {
            config(['services.google.client_secret' => ' ']);
        }
        if (! $apple) {
            config(['services.apple.client_id' => null]);
        }
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('providers', ['google' => $google, 'apple' => $apple])->missing('services'));
        foreach (['google' => $google, 'apple' => $apple] as $provider => $enabled) {
            $response = $this->get(route('social.redirect', $provider));
            $enabled ? $response->assertRedirect() : $response->assertNotFound();
        }
    }

    public function test_every_credential_value_must_be_nonblank(): void
    {
        foreach (['google', 'apple'] as $provider) {
            foreach (['client_id', 'client_secret', 'redirect'] as $key) {
                $original = config("services.$provider.$key");
                config(["services.$provider.$key" => '  ']);
                $this->assertFalse(SocialProvider::available($provider));
                config(["services.$provider.$key" => $original]);
            }
        }
    }

    public function test_linking_then_signing_in_uses_subject_and_never_retains_provider_tokens(): void
    {
        $user = User::factory()->acceptedLegal()->create();
        $this->confirm($user);
        $state = $this->begin(link: true);
        $this->google('google-owner', 'provider@example.test');
        $this->googleCallback($state)->assertRedirect(route('settings.connections.index'))->assertSessionHas('success');
        $identity = app(Tenant::class)->within($user->agency, fn () => $user->identities()->sole());
        $this->assertSame($user->agency_id, $identity->agency_id);
        $this->assertSame('provider@example.test', $identity->email);
        $raw = json_encode(DB::table('identities')->get());
        $this->assertStringNotContainsString('private-', $raw);
        $this->googleCallback($state)->assertSessionHas('error');
        $this->assertDatabaseCount('identities', 1);
        $this->post(route('logout'));
        $state = $this->begin();
        $this->google('google-owner');
        $this->googleCallback($state)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(DB::table('identities')->value('last_used_at'));
    }

    public function test_matching_email_never_creates_or_links_accounts(): void
    {
        $user = User::factory()->create();
        $count = User::count();
        $state = $this->begin();
        $this->google('unlinked-subject', $user->email);
        $this->googleCallback($state)->assertRedirect(route('login'))->assertSessionHas('error');
        $this->assertGuest();
        $this->assertDatabaseCount('identities', 0);
        $this->assertSame($count, User::count());
    }

    public function test_invalid_expired_and_cross_provider_states_do_not_exchange_credentials(): void
    {
        $this->mock(SocialProvider::class, function ($mock) {
            $mock->shouldReceive('redirect')->andReturn(redirect('https://example.test'));
            $mock->shouldNotReceive('user');
        });
        $this->get(route('social.redirect', 'google'));
        $state = session('oauth.state');
        $this->googleCallback(str_repeat('x', 64))->assertSessionHas('error')->assertSessionMissing('oauth');
        $this->googleCallback($state)->assertSessionHas('error');
        $this->get(route('social.redirect', 'apple'));
        $this->googleCallback(session('oauth.state'))->assertSessionHas('error');
        $this->get(route('social.redirect', 'google'));
        $state = session('oauth.state');
        $this->travel(5)->minutes();
        $this->googleCallback($state)->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_cancelled_and_failed_provider_requests_are_recoverable(): void
    {
        $state = $this->begin();
        $this->get(route('social.google.callback', ['state' => $state, 'error' => 'access_denied']))->assertRedirect(route('login'))->assertSessionHas('error');
        $state = $this->begin();
        Socialite::driver('google')->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(401)]))]));
        $this->googleCallback($state)->assertRedirect(route('login'))->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_identity_conflicts_and_a_second_connection_are_refused(): void
    {
        $existing = Identity::factory()->create(['subject' => 'taken']);
        $owner = User::factory()->acceptedLegal()->create();
        $this->confirm($owner);
        $state = $this->begin(link: true);
        $this->google('taken');
        $this->googleCallback($state)->assertSessionHas('error');
        $state = $this->begin(link: true);
        $this->google('first');
        $this->googleCallback($state)->assertSessionHas('success');
        $state = $this->begin(link: true);
        $this->google('second');
        $this->googleCallback($state)->assertSessionHas('error');
        $this->assertDatabaseHas('identities', ['id' => $existing->id, 'user_id' => $existing->user_id]);
        $this->assertDatabaseHas('identities', ['user_id' => $owner->id, 'subject' => 'first']);
        $this->assertDatabaseCount('identities', 2);
    }

    public function test_linking_requires_activation_legal_acceptance_and_recent_reauthentication(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->post(route('settings.connections.store', 'google'))->assertRedirect(route('verification.notice'));
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.connections.store', 'google'))->assertRedirect(route('legal.acceptance.create'));
        $user = User::factory()->acceptedLegal()->create();
        $this->actingAs($user)->postJson(route('settings.connections.store', 'google'))->assertStatus(423);
        $this->confirm($user);
        $state = $this->begin(link: true);
        $this->actingAs(User::factory()->acceptedLegal()->create());
        $this->googleCallback($state)->assertSessionHas('error');
        $this->assertDatabaseCount('identities', 0);
    }

    public function test_platform_settings_preserve_owner_and_entered_agency_and_disconnect_when_disabled(): void
    {
        $entered = Agency::factory()->create();
        $owner = $this->actingAsPlatform($entered);
        $this->confirm($owner);
        $state = $this->begin(link: true);
        $this->google('platform-owner');
        $this->googleCallback($state)->assertSessionHas('success');
        $identity = app(Tenant::class)->within($owner->agency, fn () => $owner->identities()->sole());
        $this->assertSame($owner->agency_id, $identity->agency_id);
        config(['services.google.client_id' => null]);
        $this->get(route('settings.connections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('agency.id', $entered->id)->where('connections.0.id', $identity->id)->where('providers.google', false)->missing('connections.0.subject'));
        $this->delete(route('settings.connections.destroy', $identity->id))->assertSessionHas('success');
        $this->assertDatabaseMissing('identities', ['id' => $identity->id]);
        $this->assertSame($entered->id, session('agency'));
    }

    public function test_another_users_connection_cannot_be_disconnected(): void
    {
        $identity = Identity::factory()->create();
        $owner = User::factory()->acceptedLegal()->create();
        $this->confirm($owner);
        $this->delete(route('settings.connections.destroy', $identity->id))->assertNotFound();
        $this->assertDatabaseHas('identities', ['id' => $identity->id]);
    }

    public function test_social_sign_in_requires_two_factor_and_does_not_bypass_invitation_or_legal_gates(): void
    {
        $user = User::factory()->create(['two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'), 'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery'])), 'two_factor_confirmed_at' => now()]);
        Identity::factory()->create(['user_id' => $user->id, 'subject' => 'owner']);
        $state = $this->begin();
        $this->google('owner');
        $this->googleCallback($state)->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery'])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('legal.acceptance.create'));
        $this->post(route('logout'));
        $user->forceFill(['invited_at' => now(), 'email_verified_at' => null])->save();
        $state = $this->begin();
        $this->google('owner');
        $this->googleCallback($state)->assertRedirect(route('login'))->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_identity_constraints_refuse_duplicate_ownership_and_agency_mismatches(): void
    {
        $identity = Identity::factory()->create();
        $this->assertDatabaseRefuses('23505', fn () => Identity::factory()->create(['subject' => $identity->subject]));
        $this->assertDatabaseRefuses('23505', fn () => Identity::factory()->create(['user_id' => $identity->user_id]));
        $this->assertDatabaseRefuses('23503', fn () => Identity::factory()->create(['user_id' => $identity->user_id, 'provider' => 'apple', 'agency_id' => Agency::factory()->create()->id]));
    }

    private function apple(string $nonce, array $claims = []): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        Cache::put('socialite:Apple-JWKSet', ['keys' => [['kty' => 'RSA', 'kid' => 'test-key', 'alg' => 'RS256', 'use' => 'sig', 'n' => Base64UrlSafe::encodeUnpadded($rsa['n']), 'e' => Base64UrlSafe::encodeUnpadded($rsa['e'])]]], 300);
        $jwt = JWT::encode(array_merge(['iss' => 'https://appleid.apple.com', 'aud' => 'client-id', 'sub' => 'apple-owner', 'iat' => time(), 'exp' => time() + 120, 'nonce' => $nonce], $claims), $pem, 'RS256', 'test-key');
        Socialite::driver('apple')->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], json_encode(['id_token' => $jwt, 'access_token' => 'private-token']))]))]));
    }

    public function test_apple_post_is_sessionless_and_completion_verifies_nonce_then_consumes_relay(): void
    {
        $user = User::factory()->acceptedLegal()->create();
        Identity::factory()->create(['user_id' => $user->id, 'provider' => 'apple', 'subject' => 'apple-owner']);
        $state = $this->begin('apple');
        $this->apple(session('oauth.nonce'));
        $response = $this->post(route('social.apple.callback'), ['state' => $state, 'code' => 'apple-code', 'id_token' => 'untrusted-posted-token']);
        $response->assertStatus(303)->assertHeaderMissing('Set-Cookie')->assertHeader('Referrer-Policy', 'no-referrer');
        $location = $response->headers->get('Location');
        $this->assertStringNotContainsString('apple-code', $location);
        $this->get($location)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->get($location)->assertRedirect(route('social.failure'));
    }

    public static function invalidAppleClaims(): array
    {
        return [[['nonce' => 'wrong']], [['aud' => 'other-client']], [['iss' => 'https://attacker.test']], [['exp' => 1]]];
    }

    #[DataProvider('invalidAppleClaims')]
    public function test_apple_rejects_invalid_token_claims(array $claims): void
    {
        $user = User::factory()->acceptedLegal()->create();
        Identity::factory()->create(['user_id' => $user->id, 'provider' => 'apple', 'subject' => 'apple-owner']);
        $state = $this->begin('apple');
        $this->apple(session('oauth.nonce'), $claims);
        $response = $this->post(route('social.apple.callback'), ['state' => $state, 'code' => 'apple-code']);
        $this->get($response->headers->get('Location'))->assertRedirect(route('login'))->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_apple_relay_requires_the_originating_session_and_expires(): void
    {
        $state = $this->begin('apple');
        $response = $this->post(route('social.apple.callback'), ['state' => $state, 'code' => 'apple-code']);
        $location = $response->headers->get('Location');
        $original = session('oauth');
        session()->forget('oauth');
        $this->get($location)->assertRedirect(route('social.failure'));
        $this->withSession(['oauth' => $original]);
        $this->travel(2)->minutes();
        $this->get($location)->assertRedirect(route('social.failure'));
        $this->assertGuest();
    }
}
