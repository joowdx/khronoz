<?php

namespace Tests\Feature\Policies;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Workgroup;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkgroupPolicyTest extends TestCase
{
    /**
     * Holding some OTHER permission must not grant any Workgroup ability — only
     * asserting against a zero-permission user would miss a policy that
     * (incorrectly) checked "holds any permission" rather than specifically
     * organization.view/organization.manage.
     */
    #[DataProvider('permissionsWithoutOrganizationAbilities')]
    public function test_no_ability_is_granted_by_any_permission_other_than_organization_view_or_manage(Permission $permission): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->forAgency($agency)->permissions($permission)->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Workgroup::class));
        $this->assertFalse(Gate::forUser($user)->allows('create', Workgroup::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $workgroup));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $workgroup));
    }

    public static function permissionsWithoutOrganizationAbilities(): array
    {
        return collect(Permission::cases())
            ->reject(fn (Permission $p) => in_array($p, [Permission::ViewOrganization, Permission::ManageOrganization], true))
            ->mapWithKeys(fn (Permission $p) => [$p->value => [$p]])->all();
    }

    /** organization.view grants viewAny only — never create, update or delete. There is no `view` ability: workgroups have no show route. */
    public function test_organization_view_grants_view_any_only(): void
    {
        $agency = Agency::factory()->create();
        $viewer = User::factory()->forAgency($agency)->permissions(Permission::ViewOrganization)->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $this->assertTrue(Gate::forUser($viewer)->allows('viewAny', Workgroup::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', Workgroup::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $workgroup));
        $this->assertFalse(Gate::forUser($viewer)->allows('delete', $workgroup));
    }

    /** organization.manage implies organization.view (Permission::implies()), so it grants every ability. */
    public function test_organization_manage_grants_every_ability(): void
    {
        $agency = Agency::factory()->create();
        $manager = User::factory()->forAgency($agency)->permissions(Permission::ManageOrganization)->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $this->assertTrue(Gate::forUser($manager)->allows('viewAny', Workgroup::class));
        $this->assertTrue(Gate::forUser($manager)->allows('create', Workgroup::class));
        $this->assertTrue(Gate::forUser($manager)->allows('update', $workgroup));
        $this->assertTrue(Gate::forUser($manager)->allows('delete', $workgroup));
    }

    /**
     * A platform user is allowed every ability too, but this allow comes from
     * Gate::before (AppServiceProvider::configureAuthorization) short-
     * circuiting before WorkgroupPolicy ever runs, not from any of its own method
     * bodies — mirrors UserPolicyTest's equivalent case.
     */
    public function test_platform_user_is_allowed_every_ability(): void
    {
        $user = User::factory()->platform()->create();
        $workgroup = Workgroup::factory()->create();

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', Workgroup::class));
        $this->assertTrue(Gate::forUser($user)->allows('create', Workgroup::class));
        $this->assertTrue(Gate::forUser($user)->allows('update', $workgroup));
        $this->assertTrue(Gate::forUser($user)->allows('delete', $workgroup));
    }
}
