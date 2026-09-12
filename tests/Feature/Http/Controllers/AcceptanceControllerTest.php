<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Acceptance;
use App\Models\Agency;
use App\Models\User;
use App\Support\Legal;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AcceptanceControllerTest extends TestCase
{
    private ?string $fixture = null;

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            File::deleteDirectory($this->fixture);
        }

        parent::tearDown();
    }

    public function test_guests_and_unverified_users_must_authenticate_and_activate_first(): void
    {
        $this->get(route('legal.acceptance.create'))->assertRedirect(route('login'));
        $this->post(route('legal.acceptance.store'), $this->payload())->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('legal.acceptance.create'))->assertRedirect(route('verification.notice'));
    }

    public function test_unacknowledged_users_cannot_read_or_mutate_business_data(): void
    {
        $user = User::factory()->platform()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('legal.acceptance.create'));
        $this->post(route('platform.agencies.store'), ['code' => 'blocked', 'name' => 'Blocked'])
            ->assertRedirect(route('legal.acceptance.create'));
        $this->assertDatabaseMissing('agencies', ['code' => 'blocked']);
        $this->get(route('legal.acceptance.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('legal/acceptance')->has('documents', 2));
        $this->get('/privacy-policy')->assertOk();
        $this->get('/user-agreement')->assertOk();
        $this->get(route('home'))->assertOk();
        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
        $this->assertDatabaseCount('acceptances', 0);
    }

    public function test_both_controls_are_required_and_failure_records_neither_document(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = $this->payload();
        $payload['documents']['user-agreement']['accepted'] = false;

        $this->post(route('legal.acceptance.store'), $payload)
            ->assertSessionHasErrors(['documents.user-agreement.accepted' => 'Please check this acknowledgment.']);
        $this->assertDatabaseCount('acceptances', 0);

        unset($payload['documents']['privacy-policy']);
        $this->post(route('legal.acceptance.store'), $payload)->assertSessionHasErrors('documents.privacy-policy');
        $this->assertDatabaseCount('acceptances', 0);
    }

    public function test_stale_versions_and_hashes_cannot_be_accepted(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['version' => 'outdated', 'hash' => str_repeat('0', 64)] as $field => $value) {
            $payload = $this->payload();
            $payload['documents']['privacy-policy'][$field] = $value;
            $this->post(route('legal.acceptance.store'), $payload)->assertSessionHasErrors('form');
            $this->assertDatabaseCount('acceptances', 0);
        }
    }

    public function test_recording_acknowledgments_is_idempotent_and_returns_to_the_intended_get(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard?period=2026-09')->assertRedirect(route('legal.acceptance.create'));
        $this->post(route('legal.acceptance.store'), $this->payload())->assertRedirect('/dashboard?period=2026-09');
        $rows = DB::table('acceptances')->where('user_id', $user->id)->orderBy('document')->get();
        $this->assertCount(2, $rows);
        foreach (app(Legal::class)->current() as $document) {
            $this->assertDatabaseHas('acceptances', [
                'user_id' => $user->id, 'agency_id' => $user->agency_id, 'document' => $document['slug'],
                'version' => $document['version'], 'content_hash' => $document['hash'],
            ]);
        }
        $this->travel(1)->hour();
        $this->post(route('legal.acceptance.store'), $this->payload())->assertRedirect(route('dashboard'));
        $this->assertEquals($rows, DB::table('acceptances')->where('user_id', $user->id)->orderBy('document')->get());
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('legal.acceptance.create'))->assertRedirect(route('dashboard'));
    }

    public function test_partial_prior_acknowledgment_does_not_unlock_the_app(): void
    {
        $user = User::factory()->create();
        Acceptance::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('legal.acceptance.create'));
        $this->post(route('legal.acceptance.store'), $this->payload())->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('acceptances', 2);
    }

    public function test_switching_agency_cannot_change_acknowledgment_ownership_or_accept_for_another_user(): void
    {
        $agency = Agency::factory()->create();
        $other = User::factory()->forAgency($agency)->create();
        $platform = User::factory()->platform()->create();
        $payload = [...$this->payload(), 'user_id' => $other->id, 'agency_id' => $agency->id];
        $this->actingAs($platform)->withSession(['agency' => $agency->id])
            ->post(route('legal.acceptance.store'), $payload)->assertRedirect(route('dashboard'));
        $this->assertSame($agency->id, app(Tenant::class)->id());
        $this->assertDatabaseMissing('acceptances', ['user_id' => $other->id]);
        $this->assertSame(2, DB::table('acceptances')->where('agency_id', $platform->agency_id)->count());
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_draft_acknowledgments_do_not_qualify_for_a_published_version(): void
    {
        $user = User::factory()->acceptedLegal()->create();
        $this->publish('1.0');
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('legal.acceptance.create'));
        $this->post(route('legal.acceptance.store'), $this->payload())->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
        $this->assertDatabaseCount('acceptances', 4);

        $this->publish('2.0');
        $this->get(route('dashboard'))->assertRedirect(route('legal.acceptance.create'));
    }

    public function test_production_refuses_draft_acknowledgments_even_if_a_user_rehearsed_them(): void
    {
        $user = User::factory()->acceptedLegal()->create();
        $this->app['env'] = 'production';
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('legal.acceptance.create'));
        $this->get(route('legal.acceptance.create'))->assertStatus(503);
        $this->withSession(['_token' => 'publication-test-token'])
            ->post(route('legal.acceptance.store'), [...$this->payload(), '_token' => 'publication-test-token'])
            ->assertStatus(503);
        $this->get('/privacy-policy')->assertOk();
    }

    public function test_an_external_intended_destination_is_ignored(): void
    {
        $this->actingAs(User::factory()->create())->withSession(['legal.intended' => '//example.com'])
            ->post(route('legal.acceptance.store'), $this->payload())->assertRedirect(route('dashboard'));
    }

    public function test_a_conflicting_second_acknowledgment_rolls_back_the_first(): void
    {
        $user = User::factory()->create();
        $agreement = app(Legal::class)->document('user-agreement');
        Acceptance::factory()->create([
            'user_id' => $user->id, 'document' => 'user-agreement',
            'version' => $agreement['version'], 'content_hash' => str_repeat('0', 64),
        ]);

        $this->actingAs($user)->post(route('legal.acceptance.store'), $this->payload())
            ->assertSessionHasErrors('form');

        $this->assertDatabaseCount('acceptances', 1);
        $this->assertDatabaseMissing('acceptances', ['document' => 'privacy-policy']);
        $this->get(route('dashboard'))->assertRedirect(route('legal.acceptance.create'));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $documents = [];
        foreach (app(Legal::class)->current() as $document) {
            $documents[$document['slug']] = ['version' => $document['version'], 'hash' => $document['hash'], 'accepted' => true];
        }

        return ['documents' => $documents];
    }

    private function publish(string $version): void
    {
        if ($this->fixture === null) {
            $this->fixture = sys_get_temp_dir().'/khronoz-acceptance-'.Str::uuid();
            File::copyDirectory(resource_path('legal'), $this->fixture);
            $this->app->instance(Legal::class, new Legal($this->fixture));
        }
        $path = $this->fixture.'/manifest.json';
        $catalog = json_decode(File::get($path), true);
        foreach (Legal::DOCUMENTS as $slug) {
            $markdown = '# Published '.$slug.' '.$version;
            File::put($this->fixture.'/'.$slug.'/'.$version.'.md', $markdown);
            $catalog[$slug]['current'] = $version;
            $catalog[$slug]['versions'][$version] = [
                'status' => 'published', 'effective_at' => '2026-09-12', 'sha256' => hash('sha256', $markdown),
            ];
        }
        File::put($path, json_encode($catalog));
    }
}
