<?php

namespace Tests\Feature\Policies;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    #[DataProvider('permissionsWithoutUsersManage')]
    public function test_no_ability_is_granted_by_any_permission_other_than_manage_users(Permission $permission): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->forAgency($agency)->permissions($permission)->create();
        $colleague = User::factory()->forAgency($agency)->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($user)->allows('create', User::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $colleague));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $colleague));
    }

    public static function permissionsWithoutUsersManage(): array
    {
        return collect(Permission::cases())->reject(fn (Permission $p) => $p === Permission::ManageUsers)
            ->mapWithKeys(fn (Permission $p) => [$p->value => [$p]])->all();
    }

    public function test_manage_users_grants_view_any_create_and_update(): void
    {
        $agency = Agency::factory()->create();
        $admin = User::factory()->forAgency($agency)->permissions(Permission::ManageUsers)->create();
        $colleague = User::factory()->forAgency($agency)->create();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($admin)->allows('create', User::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $colleague));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $colleague));
    }

    public function test_manage_users_does_not_permit_deleting_self(): void
    {
        $admin = User::factory()->permissions(Permission::ManageUsers)->create();

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $admin));
    }

    public function test_platform_user_is_allowed_every_ability_including_deleting_self(): void
    {
        $user = User::factory()->platform()->create();
        $colleague = User::factory()->create();

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($user)->allows('create', User::class));
        $this->assertTrue(Gate::forUser($user)->allows('update', $colleague));
        $this->assertTrue(Gate::forUser($user)->allows('delete', $colleague));
        $this->assertTrue(Gate::forUser($user)->allows('delete', $user));
    }
}
