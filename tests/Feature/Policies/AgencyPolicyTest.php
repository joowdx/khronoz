<?php

namespace Tests\Feature\Policies;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AgencyPolicyTest extends TestCase
{
    /**
     * The non-platform path: Gate::before (AppServiceProvider::configureAuthorization)
     * returns null rather than true for a user who is not platform, so this
     * actually reaches AgencyPolicy's own method bodies — unlike the
     * platform-user case below, which never does.
     */
    public function test_non_platform_user_is_denied_every_ability(): void
    {
        $user = User::factory()->create();
        $agency = Agency::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Agency::class));
        $this->assertFalse(Gate::forUser($user)->allows('view', $agency));
        $this->assertFalse(Gate::forUser($user)->allows('create', Agency::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $agency));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $agency));
        $this->assertFalse(Gate::forUser($user)->allows('restore', $agency));
        $this->assertFalse(Gate::forUser($user)->allows('forceDelete', $agency));
    }

    /**
     * For contrast: a platform user is allowed every ability too, but this
     * allow comes from Gate::before short-circuiting before AgencyPolicy ever
     * runs, not from any of the policy's own method bodies — this test
     * documents that composite behaviour rather than exercising the policy.
     */
    public function test_platform_user_is_allowed_every_ability(): void
    {
        $user = User::factory()->platform()->create();
        $agency = Agency::factory()->create();

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', Agency::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $agency));
        $this->assertTrue(Gate::forUser($user)->allows('create', Agency::class));
        $this->assertTrue(Gate::forUser($user)->allows('update', $agency));
        $this->assertTrue(Gate::forUser($user)->allows('delete', $agency));
        $this->assertTrue(Gate::forUser($user)->allows('restore', $agency));
        $this->assertTrue(Gate::forUser($user)->allows('forceDelete', $agency));
    }
}
