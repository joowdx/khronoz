<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\Workgroup;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgencySettingsControllerTest extends TestCase
{
    public function test_archiving_is_opt_in_and_defaults_off(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageAgency);
        $this->get(route('agency.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('agency/settings')->where('settings.ledger_archiving', false)->where('policy', null));
    }

    public function test_settings_and_default_policy_are_saved_together(): void
    {
        $agency = Agency::factory()->create(['settings' => ['night_from' => '22:00']]);
        $this->actingAsAgency($agency, Permission::ManageAgency);
        $this->patch(route('agency.settings.update'), [
            'settings' => ['ledger_archiving' => true],
            'policy' => ['template' => 'plain', 'roles' => ['employee', 'timekeeper'], 'supervisor' => 'operative', 'head_kind' => null],
        ])->assertRedirect(route('agency.settings.edit'));
        $this->assertSame(['night_from' => '22:00', 'ledger_archiving' => true], $agency->fresh()->settings);
        $this->assertDatabaseHas('policies', ['agency_id' => $agency->id, 'workgroup_id' => null, 'employee_id' => null, 'template' => 'plain']);
    }

    public function test_setting_updates_require_agency_management(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);
        $this->patch(route('agency.settings.update'), ['settings' => ['ledger_archiving' => true]])->assertForbidden();
    }

    public function test_unknown_settings_are_rejected(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageAgency);
        $this->patch(route('agency.settings.update'), ['settings' => ['ledger_archiving' => true, 'bucket' => 'public']])
            ->assertSessionHasErrors('settings');
    }

    public function test_roles_are_unique_and_head_requires_a_kind(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageAgency);
        $this->patch(route('agency.settings.update'), ['settings' => ['ledger_archiving' => false], 'policy' => ['roles' => ['head', 'head']]])
            ->assertSessionHasErrors(['policy.roles.0', 'policy.head_kind']);
    }

    public function test_form48_requires_at_least_two_attestation_roles(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->for($agency)->create();
        $workgroup = Workgroup::factory()->for($agency)->create();
        $this->actingAsAgency($agency, Permission::ManageAgency);

        $this->patch(route('agency.settings.update'), [
            'settings' => ['ledger_archiving' => false],
            'policy' => ['template' => 'form48', 'roles' => ['employee']],
        ])->assertSessionHasErrors([
            'policy.roles' => 'CSC Form 48 requires at least two attestation roles.',
        ]);
        $this->put(route('employees.policy.update', $employee), [
            'template' => 'form48', 'roles' => ['employee'],
        ])->assertSessionHasErrors([
            'roles' => 'CSC Form 48 requires at least two attestation roles.',
        ]);
        $this->put(route('workgroups.policy.update', $workgroup), [
            'template' => 'form48', 'roles' => ['employee'],
        ])->assertSessionHasErrors([
            'roles' => 'CSC Form 48 requires at least two attestation roles.',
        ]);

        $this->assertDatabaseCount('policies', 0);
    }

    public function test_boolean_form_values_are_saved_as_booleans(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageAgency);
        $this->patch(route('agency.settings.update'), ['settings' => ['ledger_archiving' => '1']])->assertSessionHasNoErrors();
        $this->assertTrue($agency->fresh()->settings['ledger_archiving']);
    }

    public function test_role_maps_are_rejected_before_the_database(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageAgency);
        $this->patch(route('agency.settings.update'), ['settings' => ['ledger_archiving' => false], 'policy' => ['roles' => ['signer' => 'employee']]])
            ->assertSessionHasErrors('policy.roles');
    }

    public function test_nullable_employee_policy_fields_inherit_and_upsert_in_place(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $policy = Policy::factory()->create(['agency_id' => $agency->id, 'employee_id' => $employee->id, 'template' => 'plain']);
        $this->actingAsAgency($agency, Permission::ManageAgency);
        $this->put(route('employees.policy.update', $employee), ['template' => null, 'roles' => ['timekeeper'], 'supervisor' => null, 'head_kind' => null])
            ->assertRedirect(route('agency.settings.edit'));
        $this->assertNull($policy->fresh()->template);
        $this->assertSame(['timekeeper'], $policy->fresh()->roles);
        $this->assertDatabaseCount('policies', 1);
    }

    public function test_workgroup_policy_is_scoped_to_the_bound_workgroup(): void
    {
        $agency = Agency::factory()->create();
        $group = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $other = Workgroup::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageAgency);
        $this->put(route('workgroups.policy.update', $group), ['template' => 'plain', 'employee_id' => 'untrusted'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('policies', ['workgroup_id' => $group->id, 'employee_id' => null, 'template' => 'plain']);
        $this->put(route('workgroups.policy.update', $other), ['template' => 'plain'])->assertNotFound();
    }

    public function test_scoped_policies_require_a_kind_when_the_head_role_is_selected(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageAgency);

        $this->put(route('employees.policy.update', $employee), ['roles' => ['head']])
            ->assertSessionHasErrors('head_kind');
        $this->put(route('workgroups.policy.update', $workgroup), ['roles' => ['head']])
            ->assertSessionHasErrors('head_kind');
    }
}
