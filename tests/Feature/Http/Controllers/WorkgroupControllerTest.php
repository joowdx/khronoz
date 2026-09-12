<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkgroupControllerTest extends TestCase
{
    #[DataProvider('permissionsWithoutOrganizationView')]
    public function test_workgroups_without_organization_view_are_forbidden_from_index(Permission $permission): void
    {
        $this->actingAsAgency(Agency::factory()->create(), $permission);

        $this->get(route('workgroups.index'))->assertForbidden();
    }

    public static function permissionsWithoutOrganizationView(): array
    {
        return collect(Permission::cases())
            ->reject(fn (Permission $p) => in_array($p, [Permission::ViewOrganization, Permission::ManageOrganization], true))
            ->mapWithKeys(fn (Permission $p) => [$p->value => [$p]])->all();
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function organizationManagementRouteCases(): array
    {
        return [
            'create' => ['get', 'workgroups.create', false],
            'store' => ['post', 'workgroups.store', false],
            'edit' => ['get', 'workgroups.edit', true],
            'update' => ['put', 'workgroups.update', true],
            'destroy' => ['delete', 'workgroups.destroy', true],
        ];
    }

    #[DataProvider('organizationManagementRouteCases')]
    public function test_view_only_is_forbidden_from_every_workgroup_management_route(string $verb, string $routeName, bool $needsWorkgroup): void
    {
        $agency = Agency::factory()->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $url = $needsWorkgroup ? route($routeName, $workgroup) : route($routeName);

        $this->{$verb}($url)->assertForbidden();
    }

    public function test_index_counts_who_is_in_each_workgroup_now_and_who_ever_was(): void
    {
        $agency = Agency::factory()->create();
        $busy = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Aaa Busy']);
        $vacated = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Mmm Vacated']);
        Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Zzz Untouched']);

        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Deployment::factory()->create([
            'agency_id' => $agency->id, 'employee_id' => $employee->id, 'workgroup_id' => $vacated->id,
            'starts' => '2020-01-01', 'ends' => '2021-12-31',
        ]);
        Deployment::factory()->create([
            'agency_id' => $agency->id, 'employee_id' => $employee->id, 'workgroup_id' => $busy->id,
            'starts' => '2022-01-01', 'ends' => null,
        ]);

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('workgroups.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('workgroups', 3)
                ->where('workgroups.0.people_count', 1)
                ->where('workgroups.0.deployments_count', 1)
                ->where('workgroups.1.people_count', 0)
                ->where('workgroups.1.deployments_count', 1)
                ->where('workgroups.2.people_count', 0)
                ->where('workgroups.2.deployments_count', 0));
    }

    public function test_the_headcount_covers_everything_under_the_workgroup(): void
    {
        $agency = Agency::factory()->create();
        $department = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Aaa Department']);
        $division = Workgroup::factory()->under($department)->create(['name' => 'Bbb Division']);
        $section = Workgroup::factory()->under($division)->create(['name' => 'Ccc Section']);

        foreach ([$department->id => 1, $division->id => 2, $section->id => 3] as $workgroupId => $people) {
            for ($i = 0; $i < $people; $i++) {
                Deployment::factory()->create([
                    'agency_id' => $agency->id,
                    'employee_id' => Employee::factory()->create(['agency_id' => $agency->id])->id,
                    'workgroup_id' => $workgroupId,
                    'starts' => '2022-01-01',
                    'ends' => null,
                ]);
            }
        }

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('workgroups.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('workgroups', 3)
                // 1 of its own, 5 below it.
                ->where('workgroups.0.people_count', 6)
                ->where('workgroups.1.people_count', 5)
                ->where('workgroups.2.people_count', 3)
                // Never rolled up: this one guards Remove against a RESTRICT
                // on the workgroup's own deployment rows.
                ->where('workgroups.0.deployments_count', 1)
                ->where('workgroups.1.deployments_count', 2)
                ->where('workgroups.2.deployments_count', 3));
    }

    public function test_the_head_picker_offers_only_this_agency_s_employees_who_are_still_employed(): void
    {
        $agency = Agency::factory()->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $employed = Employee::factory()->create(['agency_id' => $agency->id]);
        Deployment::factory()->create(['agency_id' => $agency->id, 'employee_id' => $employed->id, 'workgroup_id' => $workgroup->id]);
        Deployment::factory()->closed()->create(['agency_id' => $agency->id, 'workgroup_id' => $workgroup->id]);
        Employee::factory()->create(['agency_id' => $agency->id]); // never placed
        Deployment::factory()->create(); // another agency entirely

        $this->actingAsAgency($agency, Permission::ManageOrganization);

        foreach ([route('workgroups.index'), route('workgroups.create'), route('workgroups.edit', $workgroup)] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('employees', 1)
                    ->where('employees.0.id', $employed->id));
        }
    }

    public function test_the_forms_carry_the_tree_for_their_parent_picker(): void
    {
        $agency = Agency::factory()->create();
        $parent = Workgroup::factory()->create(['agency_id' => $agency->id]);
        Workgroup::factory()->under($parent)->create();
        Workgroup::factory()->create(); // another agency entirely

        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->get(route('workgroups.create'))
            ->assertInertia(fn (Assert $page) => $page->component('workgroups/create', false)->has('workgroups', 2));

        $this->get(route('workgroups.edit', $parent))
            ->assertInertia(fn (Assert $page) => $page->component('workgroups/edit', false)
                ->where('workgroup.id', $parent->id)
                ->has('workgroups', 2));
    }

    public function test_index_lists_every_workgroup_of_the_current_agency_flat_with_its_head(): void
    {
        $agency = Agency::factory()->create();
        $head = Employee::factory()->create(['agency_id' => $agency->id]);
        $parent = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Aaa Root', 'head_id' => $head->id]);
        Deployment::factory()->create(['agency_id' => $agency->id, 'employee_id' => $head->id, 'workgroup_id' => $parent->id]);
        $child = Workgroup::factory()->under($parent)->create(['name' => 'Zzz Child']);
        Workgroup::factory()->create(); // another agency entirely

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('workgroups.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workgroups/index', false)
                ->has('workgroups', 2)
                ->where('workgroups.0.id', $parent->id)
                ->where('workgroups.0.head.id', $head->id)
                ->where('workgroups.1.id', $child->id)
                ->where('workgroups.1.parent_id', $parent->id));
    }

    public function test_create_renders_the_workgroup_create_form(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->get(route('workgroups.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workgroups/create', false));
    }

    public function test_store_creates_a_workgroup(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('workgroups.store'), ['code' => 'HR', 'name' => 'Human Resources'])
            ->assertRedirect(route('workgroups.index'))->assertSessionHas('success');

        $workgroup = Workgroup::withoutGlobalScopes()->where('code', 'HR')->firstOrFail();
        $this->assertSame($agency->id, $workgroup->agency_id);
    }

    public function test_store_requires_a_unique_code_per_agency(): void
    {
        $agency = Agency::factory()->create();
        Workgroup::factory()->create(['agency_id' => $agency->id, 'code' => 'HR']);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('workgroups.store'), ['code' => 'HR', 'name' => 'Duplicate'])
            ->assertSessionHasErrors('code');
    }

    public function test_store_requires_a_unique_code_per_agency_case_insensitively(): void
    {
        $agency = Agency::factory()->create();
        Workgroup::factory()->create(['agency_id' => $agency->id, 'code' => 'HR']);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('workgroups.store'), ['code' => 'hr', 'name' => 'Duplicate'])
            ->assertSessionHasErrors(['code' => 'Already taken']);
    }

    public function test_store_requires_the_parent_to_belong_to_the_current_agency(): void
    {
        $foreignParent = Workgroup::factory()->create();
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('workgroups.store'), ['code' => 'HR', 'name' => 'Human Resources', 'parent_id' => $foreignParent->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_edit_renders_the_workgroup_being_edited_with_its_head(): void
    {
        $agency = Agency::factory()->create();
        $head = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id, 'head_id' => $head->id]);
        Deployment::factory()->create(['agency_id' => $agency->id, 'employee_id' => $head->id, 'workgroup_id' => $workgroup->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->get(route('workgroups.edit', $workgroup))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workgroups/edit', false)
                ->where('workgroup.id', $workgroup->id)
                ->where('workgroup.head.id', $head->id));
    }

    public function test_update_persists_changes(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Old']);

        $this->put(route('workgroups.update', $workgroup), ['code' => $workgroup->code, 'name' => 'New'])
            ->assertRedirect(route('workgroups.index'))->assertSessionHas('success');

        $this->assertSame('New', $workgroup->fresh()->name);
    }

    public function test_update_requires_a_unique_code_per_agency_case_insensitively(): void
    {
        $agency = Agency::factory()->create();
        Workgroup::factory()->create(['agency_id' => $agency->id, 'code' => 'HR']);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id, 'code' => 'FIN']);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->put(route('workgroups.update', $workgroup), ['code' => 'hr', 'name' => $workgroup->name])
            ->assertSessionHasErrors(['code' => 'Already taken']);
    }

    public function test_destroy_removes_the_workgroup(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $this->delete(route('workgroups.destroy', $workgroup))
            ->assertRedirect(route('workgroups.index'))->assertSessionHas('success');

        $this->assertModelMissing($workgroup);
    }

    public function test_update_refuses_a_parent_that_is_one_of_the_workgroups_own(): void
    {
        $agency = Agency::factory()->create();
        $parent = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $child = Workgroup::factory()->under($parent)->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->put(route('workgroups.update', $parent), [
            'code' => $parent->code,
            'name' => $parent->name,
            'parent_id' => $child->id,
        ])->assertSessionHasErrors(['parent_id' => 'Under one of its own workgroups.']);

        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_update_refuses_a_workgroup_as_its_own_parent(): void
    {
        $agency = Agency::factory()->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->put(route('workgroups.update', $workgroup), [
            'code' => $workgroup->code,
            'name' => $workgroup->name,
            'parent_id' => $workgroup->id,
        ])->assertSessionHasErrors(['parent_id' => 'Cannot be its own parent.']);

        $this->assertNull($workgroup->fresh()->parent_id);
    }

    /** @return array<string, array{0: string}> */
    public static function undeletableWorkgroupCases(): array
    {
        return ['a child workgroup' => ['child'], 'a closed deployment' => ['history']];
    }

    #[DataProvider('undeletableWorkgroupCases')]
    public function test_destroy_reports_what_is_still_in_the_workgroup(string $holder): void
    {
        $agency = Agency::factory()->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);

        if ($holder === 'child') {
            Workgroup::factory()->under($workgroup)->create();
        } else {
            Deployment::factory()->create([
                'agency_id' => $agency->id,
                'employee_id' => Employee::factory()->create(['agency_id' => $agency->id])->id,
                'workgroup_id' => $workgroup->id,
                'starts' => '2020-01-01',
                'ends' => '2021-12-31',
            ]);
        }

        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->delete(route('workgroups.destroy', $workgroup))
            ->assertRedirect(route('workgroups.index'))
            ->assertSessionHas('error', "{$workgroup->name} cannot be removed while a workgroup sits under it or anyone has ever been deployed to it.")
            ->assertSessionMissing('success');

        $this->assertModelExists($workgroup);
    }

    public function test_destroy_removes_a_workgroup_that_has_a_head(): void
    {
        $agency = Agency::factory()->create();
        $head = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id, 'head_id' => $head->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->delete(route('workgroups.destroy', $workgroup))
            ->assertRedirect(route('workgroups.index'))->assertSessionHas('success');

        $this->assertModelMissing($workgroup);
        $this->assertModelExists($head);
    }

    /** @return array<string, array{0: string}> */
    public static function ineligibleHeadCases(): array
    {
        return ['departed' => ['departed'], 'unplaced' => ['unplaced'], 'removed' => ['removed']];
    }

    #[DataProvider('ineligibleHeadCases')]
    public function test_store_refuses_a_head_who_cannot_run_a_workgroup(string $state): void
    {
        $agency = Agency::factory()->create();
        $head = $this->ineligibleHead($agency, $state);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('workgroups.store'), ['code' => 'HR', 'name' => 'Human Resources', 'head_id' => $head->id])
            ->assertSessionHasErrors('head_id');

        $this->assertDatabaseMissing('workgroups', ['code' => 'HR']);
    }

    #[DataProvider('ineligibleHeadCases')]
    public function test_update_refuses_a_head_who_cannot_run_a_workgroup(string $state): void
    {
        $agency = Agency::factory()->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $head = $this->ineligibleHead($agency, $state);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->put(route('workgroups.update', $workgroup), ['code' => $workgroup->code, 'name' => $workgroup->name, 'head_id' => $head->id])
            ->assertSessionHasErrors('head_id');

        $this->assertNull($workgroup->fresh()->head_id);
    }

    private function ineligibleHead(Agency $agency, string $state): Employee
    {
        $head = Employee::factory()->create(['agency_id' => $agency->id]);
        if ($state === 'departed') {
            Deployment::factory()->closed()->create(['agency_id' => $agency->id, 'employee_id' => $head->id]);
        } elseif ($state === 'removed') {
            Deployment::factory()->create(['agency_id' => $agency->id, 'employee_id' => $head->id]);
            $head->delete();
        }

        return $head;
    }

    public function test_editing_a_workgroup_of_another_agency_is_not_found(): void
    {
        $stranger = Workgroup::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->get(route('workgroups.edit', $stranger))->assertNotFound();
    }

    public function test_updating_a_workgroup_of_another_agency_is_not_found(): void
    {
        $stranger = Workgroup::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->put(route('workgroups.update', $stranger), ['code' => 'X', 'name' => 'X'])->assertNotFound();
    }

    public function test_destroying_a_workgroup_of_another_agency_is_not_found(): void
    {
        $stranger = Workgroup::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->delete(route('workgroups.destroy', $stranger))->assertNotFound();
    }

    public function test_an_employee_with_an_open_placement_can_head_a_new_and_an_existing_workgroup(): void
    {
        $placement = Deployment::factory()->create();
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);

        $this->post(route('workgroups.store'), ['code' => 'HEAD', 'name' => 'Headed workgroup', 'head_id' => $placement->employee_id])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('workgroups', ['code' => 'HEAD', 'head_id' => $placement->employee_id]);

        $workgroup = Workgroup::findOrFail($placement->workgroup_id);
        $this->put(route('workgroups.update', $workgroup), ['code' => $workgroup->code, 'name' => $workgroup->name, 'head_id' => $placement->employee_id])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame($placement->employee_id, $workgroup->fresh()->head_id);
    }
}
