<?php

namespace Tests\Feature\Policies;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AgencyPolicyTest extends TestCase
{
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
