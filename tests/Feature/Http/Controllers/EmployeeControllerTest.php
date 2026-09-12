<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\FanOutRecompute;
use App\Models\Agency;
use App\Models\Cadence;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeControllerTest extends TestCase
{
    public function test_a_new_employee_accepts_an_active_agency_cadence(): void
    {
        $agency = Agency::factory()->create();
        $cadence = Cadence::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.store'), ['number' => 'CAD-1', 'first_name' => 'Amihan', 'last_name' => 'Reyes', 'cadence_id' => $cadence->id])
            ->assertRedirect(route('employees.index'));

        $this->assertDatabaseHas('employees', ['agency_id' => $agency->id, 'number' => 'CAD-1', 'cadence_id' => $cadence->id]);
    }

    public function test_new_cadence_assignments_refuse_retired_and_foreign_choices(): void
    {
        $agency = Agency::factory()->create();
        $retired = Cadence::factory()->create(['agency_id' => $agency->id, 'retired_at' => now()]);
        $foreign = Cadence::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        foreach ([$retired, $foreign] as $cadence) {
            $this->post(route('employees.store'), ['number' => 'CAD-1', 'first_name' => 'Amihan', 'last_name' => 'Reyes', 'cadence_id' => $cadence->id])
                ->assertSessionHasErrors('cadence_id');
        }

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_an_employee_may_keep_their_existing_retired_cadence(): void
    {
        $agency = Agency::factory()->create();
        $cadence = Cadence::factory()->create(['agency_id' => $agency->id, 'retired_at' => now()]);
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'cadence_id' => $cadence->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->put(route('employees.update', $employee), ['number' => $employee->number, 'first_name' => 'Amihan', 'last_name' => 'Reyes', 'cadence_id' => $cadence->id])
            ->assertRedirect(route('employees.index'));

        $this->assertSame($cadence->id, $employee->fresh()->cadence_id);
        $this->get(route('employees.edit', $employee))->assertInertia(fn (Assert $page) => $page
            ->where('employee.cadence_id', $cadence->id)->where('cadences.0.id', $cadence->id));
    }

    #[DataProvider('permissionsWithoutOrganizationView')]
    public function test_employees_without_organization_view_are_forbidden_from_index(Permission $permission): void
    {
        $this->actingAsAgency(Agency::factory()->create(), $permission);

        $this->get(route('employees.index'))->assertForbidden();
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
            'create' => ['get', 'employees.create', false],
            'store' => ['post', 'employees.store', false],
            'edit' => ['get', 'employees.edit', true],
            'update' => ['put', 'employees.update', true],
            'destroy' => ['delete', 'employees.destroy', true],
        ];
    }

    #[DataProvider('organizationManagementRouteCases')]
    public function test_view_only_is_forbidden_from_every_employee_management_route(string $verb, string $routeName, bool $needsEmployee): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $url = $needsEmployee ? route($routeName, $employee) : route($routeName);

        $this->{$verb}($url)->assertForbidden();
    }

    private function useDatabaseSearchDriver(): void
    {
        config(['scout.driver' => 'database']);
    }

    public function test_index_lists_only_the_current_agency(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        Employee::factory()->count(2)->create(['agency_id' => $agency->id]);
        Employee::factory()->count(3)->create(); // other agencies

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index'))
            ->assertInertia(fn (Assert $page) => $page->component('employees/index', false)->has('employees', 2));
    }

    public function test_index_renders_two_employees_including_their_current_workgroup(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Treasury']);
        $deployed = Employee::factory()->create(['agency_id' => $agency->id, 'first_name' => 'X', 'last_name' => 'Aaa Deployed']);
        $undeployed = Employee::factory()->create(['agency_id' => $agency->id, 'first_name' => 'X', 'last_name' => 'Zzz Undeployed']);
        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $deployed->id,
            'workgroup_id' => $workgroup->id,
            'starts' => '2020-01-01',
            'ends' => null,
        ]);

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('employees/index', false)
                ->has('employees', 2)
                ->where('employees.0.id', $deployed->id)
                ->where('employees.0.current_deployment.workgroup.name', 'Treasury')
                ->where('employees.1.id', $undeployed->id)
                ->where('employees.1.current_deployment', null));
    }

    public function test_search_results_are_scoped_to_the_current_agency(): void
    {
        $this->useDatabaseSearchDriver();
        $agencyX = Agency::factory()->create();
        $agencyY = Agency::factory()->create();
        $inX = Employee::factory()->create(['agency_id' => $agencyX->id, 'first_name' => 'Xandrathe']);
        Employee::factory()->create(['agency_id' => $agencyY->id, 'first_name' => 'Xandrathe']);

        $this->actingAsAgency($agencyX, Permission::ViewOrganization);

        $this->get(route('employees.index', ['search' => 'Xandrathe']))
            ->assertInertia(fn (Assert $page) => $page->component('employees/index', false)
                ->has('employees', 1)
                ->where('employees.0.id', $inX->id));
    }

    public function test_search_where_clause_carries_the_current_agency(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        Employee::factory()->create(['agency_id' => $agency->id]);

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, '"employees"')) {
                $queries[] = $query->sql;
            }
        });

        $this->get(route('employees.index', ['search' => 'Cruz']))->assertOk();

        $this->assertNotEmpty($queries, 'expected at least one query against "employees"');

        foreach ($queries as $sql) {
            $this->assertSame(
                2,
                substr_count($sql, 'agency_id'),
                "expected both the explicit filter and AgencyScope in: {$sql}",
            );
        }
    }

    public function test_a_short_search_term_narrows_the_list(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();

        $named = Employee::factory()->create([
            'agency_id' => $agency->id,
            'id' => '01MZQ7A'.str_repeat('0', 19),
            'first_name' => 'Ana',
            'last_name' => 'Zq7abalza',
            'middle_name' => null,
            'position' => 'Clerk',
        ]);

        foreach (['Cruz', 'Delgado'] as $index => $lastName) {
            Employee::factory()->create([
                'agency_id' => $agency->id,
                'id' => '01MZQ7B'.str_repeat('0', 18).$index,
                'first_name' => 'Ben',
                'last_name' => $lastName,
                'middle_name' => null,
                'position' => 'Clerk',
            ]);
        }

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        // Every id contains "zq7"; only one name does.
        $this->get(route('employees.index', ['search' => 'zq7']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('employees', 1)
                ->where('employees.0.id', $named->id)
                ->where('pagination.total', 1));
    }

    public function test_the_workgroup_filter_includes_everything_under_the_chosen_workgroup(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        $department = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Treasury']);
        $division = Workgroup::factory()->under($department)->create(['name' => 'Collection']);
        $elsewhere = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Legal']);

        $inDepartment = $this->deployed($agency, $department, 'Aaa');
        $inDivision = $this->deployed($agency, $division, 'Bbb');
        $this->deployed($agency, $elsewhere, 'Ccc');

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index', ['workgroup' => $department->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('employees/index', false)
                ->has('employees', 2)
                ->where('employees.0.id', $inDepartment->id)
                ->where('employees.1.id', $inDivision->id)
                ->where('filters.workgroup', $department->id)
                ->where('pagination.total', 2));
    }

    public function test_the_workgroup_filter_does_not_climb_to_a_parent(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        $department = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $division = Workgroup::factory()->under($department)->create();

        $this->deployed($agency, $department, 'Aaa');
        $inDivision = $this->deployed($agency, $division, 'Bbb');

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index', ['workgroup' => $division->id]))
            ->assertInertia(fn (Assert $page) => $page->has('employees', 1)
                ->where('employees.0.id', $inDivision->id));
    }

    public function test_the_tag_filter_narrows_to_employees_carrying_it(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        $tagged = Employee::factory()->create(['agency_id' => $agency->id, 'last_name' => 'Aaa', 'tags' => ['night', 'ward-3']]);
        Employee::factory()->create(['agency_id' => $agency->id, 'last_name' => 'Bbb', 'tags' => ['day']]);
        Employee::factory()->create(['agency_id' => $agency->id, 'last_name' => 'Ccc', 'tags' => []]);

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index', ['tag' => 'night']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('employees', 1)
                ->where('employees.0.id', $tagged->id)
                ->where('filters.tag', 'night')
                ->where('pagination.total', 1));
    }

    public function test_the_exempt_filter_narrows_to_employees_with_no_daily_time_record_expected(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        $exempt = Employee::factory()->create(['agency_id' => $agency->id, 'last_name' => 'Aaa', 'exempt' => true]);
        Employee::factory()->create(['agency_id' => $agency->id, 'last_name' => 'Bbb', 'exempt' => false]);

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index', ['exempt' => '1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('employees', 1)
                ->where('employees.0.id', $exempt->id)
                ->where('filters.exempt', true));

        $this->get(route('employees.index'))
            ->assertInertia(fn (Assert $page) => $page->has('employees', 2)->where('filters.exempt', false));
    }

    public function test_the_filters_combine(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $other = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $wanted = $this->deployed($agency, $workgroup, 'Aaa', ['tags' => ['night'], 'exempt' => true]);
        $this->deployed($agency, $workgroup, 'Bbb', ['tags' => ['night'], 'exempt' => false]);
        $this->deployed($agency, $workgroup, 'Ccc', ['tags' => ['day'], 'exempt' => true]);
        $this->deployed($agency, $other, 'Ddd', ['tags' => ['night'], 'exempt' => true]);

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index', ['workgroup' => $workgroup->id, 'tag' => 'night', 'exempt' => '1']))
            ->assertInertia(fn (Assert $page) => $page->has('employees', 1)
                ->where('employees.0.id', $wanted->id));
    }

    public function test_an_unknown_workgroup_or_tag_filter_is_dropped(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        Employee::factory()->count(2)->create(['agency_id' => $agency->id, 'tags' => ['day']]);
        $stranger = Workgroup::factory()->create(); // another agency's workgroup

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index', ['workgroup' => $stranger->id, 'tag' => 'night']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('employees', 2)
                ->where('filters.workgroup', '')
                ->where('filters.tag', ''));
    }

    public function test_index_carries_the_workgroups_and_tags_the_filters_need(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        Workgroup::factory()->count(2)->create(['agency_id' => $agency->id]);
        Workgroup::factory()->create(); // another agency's
        Employee::factory()->create(['agency_id' => $agency->id, 'tags' => ['ward-3', 'night']]);
        Employee::factory()->create(['agency_id' => $agency->id, 'tags' => ['night']]);
        Employee::factory()->create(['agency_id' => $agency->id, 'tags' => ['gone']])->delete();
        Employee::factory()->create(['tags' => ['someone-elses']]); // another agency's

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('workgroups', 2)
                ->where('tags', ['night', 'ward-3']));
    }

    public function test_a_filter_reload_returns_only_the_props_the_list_owns(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        Workgroup::factory()->create(['agency_id' => $agency->id]);
        Employee::factory()->create(['agency_id' => $agency->id, 'tags' => ['night']]);

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'employees/index',
            'X-Inertia-Partial-Data' => implode(',', $this->partialProps()),
        ])->get(route('employees.index', ['search' => 'anything']))
            ->assertOk()
            // Asserted on the JSON, not through AssertableInertia: its
            // fromTestResponse() reads the root view's `page` data, which a
            // partial reload has no root view to carry.
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'employees/index')
            ->assertJsonStructure(['props' => ['employees', 'pagination', 'filters']])
            ->assertJsonMissingPath('props.workgroups')
            ->assertJsonMissingPath('props.tags');
    }

    /** @return array<int, string> */
    private function partialProps(): array
    {
        $source = file_get_contents(__DIR__.'/../../../../resources/js/pages/employees/index.tsx');

        $this->assertNotFalse($source, 'resources/js/pages/employees/index.tsx could not be read.');
        $this->assertSame(1, preg_match('/const PARTIAL = \[(.*?)\];/s', $source, $matches), 'resources/js/pages/employees/index.tsx: could not read the PARTIAL prop list.');

        preg_match_all("/'([^']+)'/", $matches[1], $props);

        $this->assertNotEmpty($props[1]);

        return $props[1];
    }

    public function test_show_carries_the_tree_for_the_path_and_the_move_picker(): void
    {
        $agency = Agency::factory()->create();
        Workgroup::factory()->count(2)->create(['agency_id' => $agency->id]);
        Workgroup::factory()->create(); // another agency entirely
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        foreach ([Permission::ViewOrganization, Permission::ManageOrganization] as $permission) {
            $this->actingAsAgency($agency, $permission);

            $this->get(route('employees.show', $employee))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('workgroups', 2));
        }
    }

    private function deployed(Agency $agency, Workgroup $workgroup, string $lastName, array $attributes = []): Employee
    {
        $employee = Employee::factory()->create([...$attributes, 'agency_id' => $agency->id, 'last_name' => $lastName]);

        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroup->id,
            'starts' => '2020-01-01',
            'ends' => null,
        ]);

        return $employee;
    }

    public function test_create_renders_the_employee_create_form(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->get(route('employees.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('employees/create', false));
    }

    public function test_store_creates_an_employee(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.store'), [
            'number' => 'EMP0001',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'tags' => ['nurse', 'night-shift'],
        ])->assertRedirect(route('employees.index'))->assertSessionHas('success');

        $employee = Employee::withoutGlobalScopes()->where('number', 'EMP0001')->firstOrFail();
        $this->assertSame($agency->id, $employee->agency_id);
        $this->assertSame('Ana', $employee->first_name);
        $this->assertEqualsCanonicalizing(['nurse', 'night-shift'], $employee->tags);
    }

    public function test_store_requires_a_unique_number_per_agency(): void
    {
        $agency = Agency::factory()->create();
        Employee::factory()->create(['agency_id' => $agency->id, 'number' => 'EMP0001']);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.store'), [
            'number' => 'EMP0001',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
        ])->assertSessionHasErrors('number');
    }

    public function test_show_renders_the_employee_with_deployment_history(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroup->id,
            'starts' => '2024-01-01',
            'ends' => null,
        ]);

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.show', $employee))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('employees/show', false)
                ->where('employee.id', $employee->id)
                ->has('employee.deployments', 1));
    }

    public function test_edit_renders_the_employee_being_edited(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->get(route('employees.edit', $employee))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('employees/edit', false)
                ->where('employee.id', $employee->id));
    }

    public function test_update_persists_changes(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'first_name' => 'Old']);

        $this->put(route('employees.update', $employee), [
            'number' => $employee->number,
            'first_name' => 'New',
            'last_name' => $employee->last_name,
        ])->assertRedirect(route('employees.index'))->assertSessionHas('success');

        $this->assertSame('New', $employee->fresh()->first_name);
    }

    public function test_destroy_soft_deletes_the_employee(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->delete(route('employees.destroy', $employee))
            ->assertRedirect(route('employees.index'))->assertSessionHas('success');

        $this->assertSoftDeleted($employee);
    }

    public function test_showing_an_employee_of_another_agency_is_not_found(): void
    {
        $stranger = Employee::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewOrganization);

        $this->get(route('employees.show', $stranger))->assertNotFound();
    }

    public function test_editing_an_employee_of_another_agency_is_not_found(): void
    {
        $stranger = Employee::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->get(route('employees.edit', $stranger))->assertNotFound();
    }

    public function test_updating_an_employee_of_another_agency_is_not_found(): void
    {
        $stranger = Employee::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->put(route('employees.update', $stranger), [
            'number' => 'X', 'first_name' => 'X', 'last_name' => 'X',
        ])->assertNotFound();
    }

    public function test_destroying_an_employee_of_another_agency_is_not_found(): void
    {
        $stranger = Employee::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->delete(route('employees.destroy', $stranger))->assertNotFound();
    }

    public function test_the_profile_carries_the_nesting_the_history_table_renders(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);
        $reassignment = Deployment::factory()->under($placement)->create(['starts' => '2026-03-01', 'ends' => '2026-05-31']);

        $this->get(route('employees.show', $placement->employee_id))->assertInertia(fn (Assert $page) => $page
            ->where('employee.current_deployment.parent_id', null)
            ->has('employee.deployments', 2)
            ->where('employee.deployments', fn ($deployments) => collect($deployments)
                ->firstWhere('id', $reassignment->id)['parent_id'] === $placement->id
            ));
    }

    public function test_removal_closes_a_started_placement_and_reduces_the_workgroup_headcount(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 00:30:00'));
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $placement = Deployment::factory()->create(['agency_id' => $agency->id, 'workgroup_id' => $workgroup->id, 'starts' => '2026-09-10']);
        Deployment::factory()->create(['agency_id' => $agency->id, 'workgroup_id' => $workgroup->id]);

        $this->get(route('workgroups.index'))->assertInertia(fn (Assert $page) => $page->where('workgroups.0.people_count', 2));
        $this->delete(route('employees.destroy', $placement->employee_id))->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('deployments', ['id' => $placement->id, 'ends' => '2026-09-10']);
        $this->assertSoftDeleted('employees', ['id' => $placement->employee_id]);

        $trashed = Employee::withTrashed()->findOrFail($placement->employee_id);
        $this->assertSame($placement->id, $trashed->currentDeployment->id);

        $this->travelTo(CarbonImmutable::parse('2026-09-11 00:30:00'));
        $this->assertNull($trashed->fresh()->currentDeployment);
        $this->travelTo(CarbonImmutable::parse('2026-09-10 00:30:00'));
        $this->get(route('workgroups.index'))->assertInertia(fn (Assert $page) => $page
            ->where('workgroups.0.people_count', 1)->where('workgroups.0.deployments_count', 2));
    }

    public function test_removal_deletes_a_future_placement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 00:30:00'));
        $placement = Deployment::factory()->create(['starts' => '2026-09-11']);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);

        $this->delete(route('employees.destroy', $placement->employee_id))->assertRedirect()->assertSessionHas('success');

        $this->assertModelMissing($placement);
        $this->assertSoftDeleted('employees', ['id' => $placement->employee_id]);
        $this->get(route('workgroups.index'))->assertInertia(fn (Assert $page) => $page
            ->where('workgroups.0.people_count', 0)->where('workgroups.0.deployments_count', 0));
    }

    public function test_removal_preserves_closed_placement_history(): void
    {
        $placement = Deployment::factory()->closed()->create(['starts' => '2024-01-01', 'ends' => '2024-12-31']);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);

        $this->delete(route('employees.destroy', $placement->employee_id))->assertRedirect()->assertSessionHas('success');

        $this->assertSame('2024-12-31', $placement->fresh()->ends->toDateString());
        $this->assertSoftDeleted('employees', ['id' => $placement->employee_id]);
    }

    public function test_removing_an_employee_queues_a_recompute_from_today(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        Queue::fake([FanOutRecompute::class]);

        $this->delete(route('employees.destroy', $employee))->assertSessionHas('success');

        Queue::assertPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => $job->employeeIds === [$employee->id]
                && $job->from === today()->toDateString()
                && $job->to === null,
        );
    }
}
