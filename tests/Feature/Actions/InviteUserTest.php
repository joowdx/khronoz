<?php

namespace Tests\Feature\Actions;

use App\Actions\InviteUser;
use App\Enums\Permission;
use App\Models\Agency;
use App\Notifications\InviteNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class InviteUserTest extends TestCase
{
    public function test_creates_the_user_in_the_current_tenant_invited_with_the_given_permissions(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);

        $user = app(InviteUser::class)->handle([
            'name' => 'Ana Cruz',
            'email' => 'ana@agency.gov.ph',
            'permissions' => [Permission::ManageUsers->value],
        ]);

        $this->assertModelExists($user);
        $this->assertSame($agency->id, $user->agency_id);
        $this->assertNotNull($user->invited_at);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->allows(Permission::ManageUsers));
    }

    /**
     * bcrypt salts every hash, so two calls' stored hashes would differ even
     * for the same fixed plaintext — comparing hashes cannot prove
     * randomness. What is genuinely checkable, and worth guarding, is that
     * the invited user does not end up with the same weak default every
     * other factory-made user gets ("password") — a realistic copy-paste
     * regression given UserFactory literally hashes that string nearby.
     */
    public function test_does_not_give_the_invited_user_the_common_factory_default_password(): void
    {
        $this->withTenant(Agency::factory()->create());

        $user = app(InviteUser::class)->handle(['name' => 'Ana Cruz', 'email' => 'ana@agency.gov.ph', 'permissions' => []]);

        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_sends_exactly_one_invite_notification_with_a_validly_signed_url(): void
    {
        Notification::fake();
        $this->withTenant(Agency::factory()->create());

        $user = app(InviteUser::class)->handle(['name' => 'Ana Cruz', 'email' => 'ana@agency.gov.ph', 'permissions' => []]);

        Notification::assertSentToTimes($user, InviteNotification::class, 1);
        Notification::assertSentTo($user, InviteNotification::class, function (InviteNotification $notification) use ($user): bool {
            $signedUrl = $notification->toMail($user)->actionUrl;

            return URL::hasValidSignature(Request::create($signedUrl));
        });
    }
}
