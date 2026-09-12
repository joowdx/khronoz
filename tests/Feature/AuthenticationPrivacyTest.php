<?php

namespace Tests\Feature;

use App\Models\Acceptance;
use App\Models\Identity;
use App\Models\Ledger;
use App\Models\Passkey;
use App\Models\User;
use App\Notifications\EmailChangeNotification;
use App\Providers\TelescopeServiceProvider;
use App\Support\Legal;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers\RequestWatcher;
use Tests\TestCase;

class AuthenticationPrivacyTest extends TestCase
{
    public function test_the_new_notice_preserves_the_previous_version_and_acknowledgments(): void
    {
        $old = app(Legal::class)->document('privacy-policy', 'draft-2026-09-12');
        $current = app(Legal::class)->document('privacy-policy');
        $this->assertSame('ac6a59d51847d7a6c5969810f9570cf03a39f4bf09467636d4d79cd0930c5f4a', $old['hash']);
        $this->assertSame('draft-2026-09-12-accounts', $current['version']);
        $this->assertNotSame($old['hash'], $current['hash']);
        $user = User::factory()->create();
        Acceptance::factory()->create(['user_id' => $user->id, 'version' => $old['version'], 'content_hash' => $old['hash']]);
        $this->actingAs($user)->get(route('settings.profile.edit'))->assertRedirect(route('legal.acceptance.create'));
        $this->get($old['url'])->assertInertia(fn (Assert $page) => $page->where('document.hash', $old['hash']));
        $this->assertDatabaseHas('acceptances', ['user_id' => $user->id, 'content_hash' => $old['hash']]);
    }

    public function test_credential_cascades_do_not_allow_deleting_a_historical_actor(): void
    {
        $user = User::factory()->acceptedLegal()->create();
        $identity = Identity::factory()->create(['user_id' => $user->id]);
        $passkey = Passkey::factory()->create(['user_id' => $user->id]);
        Ledger::factory()->create(['agency_id' => $user->agency_id, 'locked_by' => $user->id]);
        $this->assertDatabaseRefuses('23001', fn () => $user->delete());
        $this->assertDatabaseHas('identities', ['id' => $identity->id]);
        $this->assertDatabaseHas('passkeys', ['id' => $passkey->id]);
    }

    public function test_credentials_cascade_only_for_an_otherwise_deletable_account(): void
    {
        $user = User::factory()->create();
        Identity::factory()->create(['user_id' => $user->id]);
        Passkey::factory()->create(['user_id' => $user->id]);
        $user->delete();
        $this->assertDatabaseCount('identities', 0);
        $this->assertDatabaseCount('passkeys', 0);
    }

    public function test_authentication_material_is_filtered_from_diagnostics_even_on_later_requests(): void
    {
        $originalFilters = Telescope::$filterUsing;
        $originalHidden = Telescope::$hiddenRequestParameters;
        try {
            $this->app['env'] = 'local';
            (new TelescopeServiceProvider($this->app))->register();
            $filter = Telescope::$filterUsing[array_key_last(Telescope::$filterUsing)];
            $this->app->instance('request', Request::create('/dashboard'));
            $this->assertFalse($filter(IncomingEntry::make(['sql' => 'update "sessions" set "payload" = secret'])->type(EntryType::QUERY)));
            $this->assertFalse($filter(IncomingEntry::make(['html' => 'https://example.test/settings/email/verify/secret'])->type(EntryType::MAIL)));
            $this->assertFalse($filter(IncomingEntry::make(['name' => EmailChangeNotification::class])->type(EntryType::JOB)));
            $this->assertTrue($filter(IncomingEntry::make(['sql' => 'select count(*) from employees'])->type(EntryType::QUERY)));
            $watcher = new class([]) extends RequestWatcher
            {
                public function redact(array $payload): array
                {
                    return $this->payload($payload);
                }
            };
            $redacted = $watcher->redact(['oauth' => ['state' => 'private'], 'passkey' => ['options' => 'private'], 'login' => ['password_hash' => 'private'], 'password_hash_web' => 'private']);
            $this->assertStringNotContainsString('private', json_encode($redacted));
            $this->assertInstanceOf(ShouldBeEncrypted::class, new EmailChangeNotification('private-url'));
        } finally {
            Telescope::$filterUsing = $originalFilters;
            Telescope::$hiddenRequestParameters = $originalHidden;
        }
    }
}
