<?php

namespace Tests\Feature\Policies;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeePolicyTest extends TestCase
{
    /**
     * Holding some OTHER permission must not grant any Employee ability —
     * only asserting against a zero-permission user would miss a policy that
     * (incorrectly) checked "holds any permission" rather than specifically
     * organization.view/organization.manage.
     */
    #[DataProvider('permissionsWithoutOrganizationAbilities')]
    public function test_no_ability_is_granted_by_any_permission_other_than_organization_view_or_manage(Permission $permission): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->forAgency($agency)->permissions($permission)->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Employee::class));
        $this->assertFalse(Gate::forUser($user)->allows('view', $employee));
        $this->assertFalse(Gate::forUser($user)->allows('create', Employee::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $employee));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $employee));
    }

    public static function permissionsWithoutOrganizationAbilities(): array
    {
        return collect(Permission::cases())
            ->reject(fn (Permission $p) => in_array($p, [Permission::ViewOrganization, Permission::ManageOrganization], true))
            ->mapWithKeys(fn (Permission $p) => [$p->value => [$p]])->all();
    }

    /** organization.view grants viewAny/view only — never create, update or delete. */
    public function test_organization_view_grants_view_any_and_view_only(): void
    {
        $agency = Agency::factory()->create();
        $viewer = User::factory()->forAgency($agency)->permissions(Permission::ViewOrganization)->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->assertTrue(Gate::forUser($viewer)->allows('viewAny', Employee::class));
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $employee));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', Employee::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $employee));
        $this->assertFalse(Gate::forUser($viewer)->allows('delete', $employee));
    }

    /** organization.manage implies organization.view (Permission::implies()), so it grants every ability, including view. */
    public function test_organization_manage_grants_every_ability(): void
    {
        $agency = Agency::factory()->create();
        $manager = User::factory()->forAgency($agency)->permissions(Permission::ManageOrganization)->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->assertTrue(Gate::forUser($manager)->allows('viewAny', Employee::class));
        $this->assertTrue(Gate::forUser($manager)->allows('view', $employee));
        $this->assertTrue(Gate::forUser($manager)->allows('create', Employee::class));
        $this->assertTrue(Gate::forUser($manager)->allows('update', $employee));
        $this->assertTrue(Gate::forUser($manager)->allows('delete', $employee));
    }

    /**
     * A platform user is allowed every ability too, but this allow comes from
     * Gate::before (AppServiceProvider::configureAuthorization) short-
     * circuiting before EmployeePolicy ever runs, not from any of its own
     * method bodies — mirrors UserPolicyTest's equivalent case.
     */
    public function test_platform_user_is_allowed_every_ability(): void
    {
        $user = User::factory()->platform()->create();
        $employee = Employee::factory()->create();

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', Employee::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $employee));
        $this->assertTrue(Gate::forUser($user)->allows('create', Employee::class));
        $this->assertTrue(Gate::forUser($user)->allows('update', $employee));
        $this->assertTrue(Gate::forUser($user)->allows('delete', $employee));
    }
}
