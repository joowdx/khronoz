<?php

namespace Tests\Feature\Http\Controllers\Settings;

use App\Models\Agency;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Notifications\EmailChangeNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function account(): User
    {
        $user = $this->actingAsAgency(Agency::factory()->create());
        $this->withSession(['auth.confirmed_user' => $user->id, 'auth.password_confirmed_at' => now()->timestamp]);

        return $user;
    }

    private function verificationUrl(): string
    {
        return Notification::sent(new AnonymousNotifiable, EmailChangeNotification::class)->last()->url;
    }

    public function test_profile_requires_login_verification_and_legal_acknowledgment(): void
    {
        $this->get(route('settings.profile.edit'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())->get(route('settings.profile.edit'))->assertRedirect(route('verification.notice'));
        $this->actingAs(User::factory()->create())->get(route('settings.profile.edit'))->assertRedirect(route('legal.acceptance.create'));
    }

    public function test_name_update_changes_only_the_authenticated_account(): void
    {
        $owner = $this->account();
        $other = User::factory()->create();
        $this->patch(route('settings.profile.update'), [
            'name' => 'Account Owner', 'user_id' => $other->id, 'agency_id' => $other->agency_id,
            'email' => 'attacker@example.test', 'permissions' => ['users.manage'],
        ])->assertRedirect();
        $this->assertSame('Account Owner', $owner->fresh()->name);
        $this->assertSame($owner->email, $owner->fresh()->email);
        $this->assertSame($owner->agency_id, $owner->fresh()->agency_id);
        $this->assertSame($other->name, $other->fresh()->name);
        $this->assertCount(0, $owner->fresh()->permissions);
        $this->patch(route('settings.profile.update'), ['name' => ''])->assertSessionHasErrors('name');
    }

    public function test_email_waits_for_verification_then_notifies_the_old_address(): void
    {
        $owner = $this->account();
        $oldEmail = $owner->email;
        $this->post(route('settings.email.store'), ['email' => 'New.Address@example.test'])->assertSessionHasNoErrors();
        $this->assertSame($owner->email, $owner->fresh()->email);
        $this->assertSame('new.address@example.test', $owner->fresh()->pending_email);
        $this->assertNotNull($owner->fresh()->email_verified_at);
        $url = $this->verificationUrl();
        $this->get($url)->assertRedirect(route('settings.profile.edit'));
        $this->assertSame('new.address@example.test', $owner->fresh()->email);
        $this->assertNull($owner->fresh()->pending_email);
        $this->assertNull($owner->fresh()->pending_email_token);
        Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $oldEmail && $notification->email === 'new.address@example.test');
        $this->get($url)->assertForbidden();
    }

    public function test_email_change_requires_recent_confirmation(): void
    {
        $owner = $this->account();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(5)->timestamp]);
        $this->post(route('settings.email.store'), ['email' => 'new@example.test'])->assertRedirectContains('/confirm-password');
        $this->assertNull($owner->fresh()->pending_email);
        Notification::assertNothingSent();
    }

    public function test_pending_email_is_private_and_profile_does_not_expose_the_token(): void
    {
        $owner = $this->account();
        $this->post(route('settings.email.store'), ['email' => 'new@example.test']);
        $this->get(route('settings.profile.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('pendingEmail', 'new@example.test')->missing('auth.user.pending_email')->missing('auth.user.pending_email_token'));
        $this->assertArrayNotHasKey('pending_email_token', $owner->fresh()->toArray());
    }

    public function test_resending_replaces_the_link_and_cancellation_invalidates_it(): void
    {
        $owner = $this->account();
        $this->post(route('settings.email.store'), ['email' => 'new@example.test']);
        $old = $this->verificationUrl();
        $this->post(route('settings.email.notification.store'))->assertSessionHasNoErrors();
        $new = $this->verificationUrl();
        $this->assertNotSame($old, $new);
        $this->get($old)->assertForbidden();
        $this->delete(route('settings.email.destroy'))->assertRedirect();
        $this->get($new)->assertForbidden();
        $this->assertNull($owner->fresh()->pending_email);
    }

    public function test_expired_tampered_and_wrong_account_links_are_refused(): void
    {
        $owner = $this->account();
        $this->post(route('settings.email.store'), ['email' => 'new@example.test']);
        $url = $this->verificationUrl();
        $this->get($url.'broken')->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs($owner);
        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden();
        $this->assertSame($owner->email, $owner->fresh()->email);
    }

    public function test_email_conflicts_are_checked_at_request_and_confirmation(): void
    {
        $owner = $this->account();
        $other = User::factory()->create();
        $this->post(route('settings.email.store'), ['email' => strtoupper($other->email)])->assertSessionHasErrors('email');
        $this->post(route('settings.email.store'), ['email' => 'new@example.test'])->assertSessionHasNoErrors();
        $url = $this->verificationUrl();
        User::factory()->create(['email' => 'new@example.test']);
        $this->get($url)->assertSessionHasErrors('email');
        $this->assertSame($owner->email, $owner->fresh()->email);
        $this->assertSame('new@example.test', $owner->fresh()->pending_email);
    }

    public function test_platform_user_keeps_account_ownership_while_entered_into_an_agency(): void
    {
        $agency = Agency::factory()->create();
        $owner = $this->actingAsPlatform($agency);
        $this->withSession(['auth.confirmed_user' => $owner->id, 'auth.password_confirmed_at' => now()->timestamp]);
        $this->post(route('settings.email.store'), ['email' => 'operator@example.test']);
        $this->get($this->verificationUrl())->assertRedirect(route('settings.profile.edit'));
        $this->get(route('settings.profile.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.email', 'operator@example.test')->where('agency.id', $agency->id));
        $this->assertSame($owner->agency_id, $owner->fresh()->agency_id);
    }
}
