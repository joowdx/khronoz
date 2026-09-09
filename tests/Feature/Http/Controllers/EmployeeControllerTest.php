<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Unit;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeControllerTest extends TestCase
{
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

    /**
     * Every write route, exercised by a ViewOrganization-only holder: proves
     * view access does not imply manage access. Mirrors
     * UserControllerTest::userManagementRouteCases.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
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

    /**
     * phpunit.xml pins SCOUT_DRIVER=null for the suite (so unrelated tests
     * never depend on a search backend at all) — Laravel\Scout\Engines\NullEngine
     * always reports zero results, silently, no matter what data exists. Any
     * test that exercises EmployeeController::index (which searches through
     * Scout unconditionally, even for a blank term) must opt back into the
     * real driver first, or it is testing NullEngine's empty result, not this
     * controller.
     */
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

    /**
     * The eager-loading trap (task-5-brief.md): Model::shouldBeStrict() arms
     * the lazy-loading guard on a hydrated collection only once it holds
     * more than one model (Builder::hydrate()), so a single-employee test
     * cannot exercise EmployeeController::index's `->with('currentDeployment.unit')`
     * — this fixture is deliberately two employees, one with an open
     * deployment and one without, so both branches of
     * EmployeeResource::current_deployment render.
     */
    public function test_index_renders_two_employees_including_their_current_unit(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        $unit = Unit::factory()->create(['agency_id' => $agency->id, 'name' => 'Treasury']);
        $deployed = Employee::factory()->create(['agency_id' => $agency->id, 'first_name' => 'X', 'last_name' => 'Aaa Deployed']);
        $undeployed = Employee::factory()->create(['agency_id' => $agency->id, 'first_name' => 'X', 'last_name' => 'Zzz Undeployed']);
        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $deployed->id,
            'unit_id' => $unit->id,
            'starts' => '2020-01-01',
            'ends' => null,
        ]);

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('employees/index', false)
                ->has('employees', 2)
                ->where('employees.0.id', $deployed->id)
                ->where('employees.0.current_deployment.unit.name', 'Treasury')
                ->where('employees.1.id', $undeployed->id)
                ->where('employees.1.current_deployment', null));
    }

    /**
     * R10: Employee::search() must filter agency_id explicitly, even though
     * SCOUT_DRIVER=database happens to also carry AgencyScope. Both
     * employees share the searched term so this genuinely exercises the
     * ->where('agency_id', ...) filter — with it removed, this test would
     * see both rows instead of one.
     */
    public function test_search_is_scoped_to_the_current_agency_even_though_it_would_otherwise_match(): void
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

    public function test_store_creates_an_employee(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.store'), [
            'number' => 'EMP0001',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'hired_at' => '2024-01-15',
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
            'hired_at' => '2024-01-15',
        ])->assertSessionHasErrors('number');
    }

    public function test_store_refuses_a_separation_date_before_the_hire_date(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.store'), [
            'number' => 'EMP0002',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'hired_at' => '2024-01-15',
            'separated_at' => '2024-01-01',
        ])->assertSessionHasErrors('separated_at');
    }

    public function test_show_renders_the_employee_with_deployment_history(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);
        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
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

    public function test_update_persists_changes(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'first_name' => 'Old']);

        $this->put(route('employees.update', $employee), [
            'number' => $employee->number,
            'first_name' => 'New',
            'last_name' => $employee->last_name,
            'hired_at' => $employee->hired_at->toDateString(),
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

    /**
     * The regression guard for cross-tenant route binding must be a
     * cross-tenant lookup, not a same-agency happy path (.ai/rules/middleware.md)
     * — a same-agency lookup resolves identically whether or not
     * SetTenant/SubstituteBindings ordering is correct.
     */
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
            'number' => 'X', 'first_name' => 'X', 'last_name' => 'X', 'hired_at' => '2024-01-01',
        ])->assertNotFound();
    }

    public function test_destroying_an_employee_of_another_agency_is_not_found(): void
    {
        $stranger = Employee::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->delete(route('employees.destroy', $stranger))->assertNotFound();
    }
}
