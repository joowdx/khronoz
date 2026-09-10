<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
     * R10, scope half: proves AgencyScope itself, not the explicit
     * ->where('agency_id', ...) filter in EmployeeController::index. Both
     * employees share the searched term, so narrowing to one row is real
     * coverage — it would fail if AgencyScope broke — but under the shipped
     * `database` driver it is NOT falsifiable evidence for the explicit
     * filter specifically: DatabaseEngine::newSearchQuery() falls back to
     * $builder->model->newQuery() (Employee::toSearchableArray()'s docblock),
     * which already carries AgencyScope, so removing only the controller's
     * ->where('agency_id', ...) would leave this test passing unchanged.
     * test_search_where_clause_carries_the_current_agency below covers the
     * explicit filter itself, by inspecting the SQL the database driver
     * actually sends rather than the result set.
     */
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

    /**
     * R10, explicit-filter half: the ->where('agency_id', ...) at the
     * EmployeeController::index call site exists for an external search
     * engine (Meilisearch, Algolia, Typesense) that matches against its own
     * index and never applies AgencyScope — under the shipped `database`
     * driver, results alone can't distinguish that filter from AgencyScope
     * (see the test above), so this asserts on the SQL Postgres actually
     * receives instead. DatabaseEngine::newSearchQuery() falls back to
     * Model::newQuery(), so AgencyScope alone already contributes one
     * `agency_id` condition; the controller's explicit
     * ->where('agency_id', ...) contributes a second, independent one.
     * Deleting the controller's filter drops every query against
     * "employees" from two `agency_id` conditions to one, so this fails
     * exactly when the finding says it should — unlike inspecting
     * Laravel\Scout\Builder::$wheres directly (the previous version of this
     * test), which only proves Builder::where() works — an unconditional
     * array push with no branching (vendor/laravel/scout/src/Builder.php:
     * 175-184) — and says nothing about whether the controller actually
     * called it.
     *
     * A real search term, deliberately: the count is exactly 2 either way
     * now, and a term is the shape that used to break it. DatabaseEngine
     * ilike-matches `%term%` against every key of toSearchableArray(), and
     * `agency_id` was one of them under this driver, adding a third,
     * unrelated `agency_id` substring — so this test had to search for
     * nothing at all to hold. It no longer does (Employee::toSearchableArray
     * indexes the key only for an engine that filters through its own index),
     * which makes the term free and makes this test fail if anyone puts an
     * identifier back into the array under the `database` driver.
     */
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

    /**
     * A short term must narrow the list, which is the whole job of the search
     * box: a timekeeper finds a person by typing, in a list that will hold
     * thousands.
     *
     * The fixture puts the term in every row's `id` and in exactly one row's
     * name. DatabaseEngine ilike-matches `%term%` against every key of
     * toSearchableArray(), so while `id` was one of those keys every row
     * contributed 26 characters of ULID to the match set and a one- or
     * two-character term matched everyone. MEASURED against the seeded Demo
     * Agency before the fix: `q` returned 32 of 32 employees, `n2d7` 32 of 32,
     * `zq` 0 and `Barton` 1 — the terms that failed were the short ones, i.e.
     * every term on the way to a long one.
     *
     * Ids are assigned by hand here because a ULID's own characters are not
     * knowable in advance; they stay valid Crockford base32 so nothing else
     * about the row is unusual. Restoring `'id' => $this->id` to the array
     * makes this return 3.
     */
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
            'email' => 'ana@example.test',
            'position' => 'Clerk',
        ]);

        foreach (['Cruz', 'Delgado'] as $index => $lastName) {
            Employee::factory()->create([
                'agency_id' => $agency->id,
                'id' => '01MZQ7B'.str_repeat('0', 18).$index,
                'first_name' => 'Ben',
                'last_name' => $lastName,
                'middle_name' => null,
                'email' => "ben{$index}@example.test",
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

    /**
     * P17's unit filter. The fixture is the point: three employees, one in a
     * department, one in a division *under* it, one in an unrelated division.
     * Filtering by the department must return the first two — 01-organization.md
     * rule 4 makes "this unit and everything under it" what choosing a unit
     * means, and a department whose people all sit in its divisions would
     * otherwise answer with nothing. A fixture with only a direct member would
     * pass whether or not the subtree walk existed.
     */
    public function test_the_unit_filter_includes_everything_under_the_chosen_unit(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        $department = Unit::factory()->create(['agency_id' => $agency->id, 'name' => 'Treasury']);
        $division = Unit::factory()->under($department)->create(['name' => 'Collection']);
        $elsewhere = Unit::factory()->create(['agency_id' => $agency->id, 'name' => 'Legal']);

        $inDepartment = $this->deployed($agency, $department, 'Aaa');
        $inDivision = $this->deployed($agency, $division, 'Bbb');
        $this->deployed($agency, $elsewhere, 'Ccc');

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index', ['unit' => $department->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('employees/index', false)
                ->has('employees', 2)
                ->where('employees.0.id', $inDepartment->id)
                ->where('employees.1.id', $inDivision->id)
                ->where('filters.unit', $department->id)
                ->where('pagination.total', 2));
    }

    /** The subtree is inclusive of the unit itself but not of its siblings: filtering by the child returns only the child's own. */
    public function test_the_unit_filter_does_not_climb_to_a_parent(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        $department = Unit::factory()->create(['agency_id' => $agency->id]);
        $division = Unit::factory()->under($department)->create();

        $this->deployed($agency, $department, 'Aaa');
        $inDivision = $this->deployed($agency, $division, 'Bbb');

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index', ['unit' => $division->id]))
            ->assertInertia(fn (Assert $page) => $page->has('employees', 1)
                ->where('employees.0.id', $inDivision->id));
    }

    /** P17's tag filter: jsonb containment against employees.tags, which is a set and not a string. */
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

    /** P17's exempt toggle. */
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

    /** All three at once, because the screen offers them at once and each is a separate ->when(). */
    public function test_the_filters_combine(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);
        $other = Unit::factory()->create(['agency_id' => $agency->id]);

        $wanted = $this->deployed($agency, $unit, 'Aaa', ['tags' => ['night'], 'exempt' => true]);
        $this->deployed($agency, $unit, 'Bbb', ['tags' => ['night'], 'exempt' => false]);
        $this->deployed($agency, $unit, 'Ccc', ['tags' => ['day'], 'exempt' => true]);
        $this->deployed($agency, $other, 'Ddd', ['tags' => ['night'], 'exempt' => true]);

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index', ['unit' => $unit->id, 'tag' => 'night', 'exempt' => '1']))
            ->assertInertia(fn (Assert $page) => $page->has('employees', 1)
                ->where('employees.0.id', $wanted->id));
    }

    /**
     * A unit id or a tag the tenant does not have is dropped, not applied, and
     * `filters` reports it as unset — otherwise the picker would show an empty
     * value while the list stayed filtered by something nobody can see. Same
     * whitelisting AgencyController::index gives `sort`.
     */
    public function test_an_unknown_unit_or_tag_filter_is_dropped(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        Employee::factory()->count(2)->create(['agency_id' => $agency->id, 'tags' => ['day']]);
        $stranger = Unit::factory()->create(); // another agency's unit

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index', ['unit' => $stranger->id, 'tag' => 'night']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('employees', 2)
                ->where('filters.unit', '')
                ->where('filters.tag', ''));
    }

    /**
     * The filter controls' own options. `tags` is this tenant's whole tag
     * vocabulary, once each and sorted; a soft-deleted employee's tags are not
     * part of it, which is the half a plain DISTINCT over the raw table would
     * get wrong.
     */
    public function test_index_carries_the_units_and_tags_the_filters_need(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        Unit::factory()->count(2)->create(['agency_id' => $agency->id]);
        Unit::factory()->create(); // another agency's
        Employee::factory()->create(['agency_id' => $agency->id, 'tags' => ['ward-3', 'night']]);
        Employee::factory()->create(['agency_id' => $agency->id, 'tags' => ['night']]);
        Employee::factory()->create(['agency_id' => $agency->id, 'tags' => ['gone']])->delete();
        Employee::factory()->create(['tags' => ['someone-elses']]); // another agency's

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('units', 2)
                ->where('tags', ['night', 'ward-3']));
    }

    /**
     * The partial-reload contract, which had no test at all: the brief
     * requires a filter change to reload only the props the list owns, and
     * the whole reason `units` and `tags` are sent as Inertia closures is
     * that Inertia never invokes a closure for a prop a partial reload
     * excluded — so the option lists are queried once per full load rather
     * than once per keystroke.
     *
     * `X-Inertia-Partial-Data` is read from the page component's own
     * `PARTIAL` constant rather than hard-coded, which is what makes this
     * falsifiable in the direction that matters: adding `units` to `PARTIAL`
     * puts it in the header, the closure is then invoked, and `missing`
     * fails. A hard-coded header would have gone on passing.
     */
    public function test_a_filter_reload_returns_only_the_props_the_list_owns(): void
    {
        $this->useDatabaseSearchDriver();
        $agency = Agency::factory()->create();
        Unit::factory()->create(['agency_id' => $agency->id]);
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
            ->assertJsonMissingPath('props.units')
            ->assertJsonMissingPath('props.tags');
    }

    /**
     * `PARTIAL` as employees/index.tsx declares it — the same read-the-source
     * approach PermissionMatrixContractTest and OrganizationNavContractTest
     * take, for the same reason: nothing in `tsc` or the bundle would notice
     * the list growing.
     *
     * @return array<int, string>
     */
    private function partialProps(): array
    {
        $source = file_get_contents(__DIR__.'/../../../../resources/js/pages/employees/index.tsx');

        $this->assertNotFalse($source, 'resources/js/pages/employees/index.tsx could not be read.');
        $this->assertSame(1, preg_match('/const PARTIAL = \[(.*?)\];/s', $source, $matches), 'resources/js/pages/employees/index.tsx: could not read the PARTIAL prop list.');

        preg_match_all("/'([^']+)'/", $matches[1], $props);

        $this->assertNotEmpty($props[1]);

        return $props[1];
    }

    /**
     * The profile carries the tree, for the move sheet's picker and for the
     * ancestry line under the current unit. A view-only reader gets it too:
     * the same tree is on /units for anyone holding organization.view, so
     * withholding it would only cost them the line that says where the unit
     * sits — and it stays this tenant's own tree either way.
     */
    public function test_show_carries_the_tree_for_the_path_and_the_move_picker(): void
    {
        $agency = Agency::factory()->create();
        Unit::factory()->count(2)->create(['agency_id' => $agency->id]);
        Unit::factory()->create(); // another agency entirely
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        foreach ([Permission::ViewOrganization, Permission::ManageOrganization] as $permission) {
            $this->actingAsAgency($agency, $permission);

            $this->get(route('employees.show', $employee))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('units', 2));
        }
    }

    /** An employee with an open deployment in $unit, ordered by $lastName so a filtered list's row order is assertable. */
    private function deployed(Agency $agency, Unit $unit, string $lastName, array $attributes = []): Employee
    {
        $employee = Employee::factory()->create([...$attributes, 'agency_id' => $agency->id, 'last_name' => $lastName]);

        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'starts' => '2020-01-01',
            'ends' => null,
        ]);

        return $employee;
    }

    /**
     * Minor 6: create/edit were only ever exercised for 403 (view-only) and
     * 404 (cross-tenant), never for a manager actually reaching the form —
     * so a wrong Inertia::render() component string here would first surface
     * in the next task's screens, not in this suite.
     */
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
            'number' => 'X', 'first_name' => 'X', 'last_name' => 'X',
        ])->assertNotFound();
    }

    public function test_destroying_an_employee_of_another_agency_is_not_found(): void
    {
        $stranger = Employee::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->delete(route('employees.destroy', $stranger))->assertNotFound();
    }

    /** The headcount queries deployments, so hiding the employee alone cannot fix it. */
    public function test_removal_closes_a_started_placement_and_reduces_the_unit_headcount(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 00:30:00'));
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);
        $placement = Deployment::factory()->create(['agency_id' => $agency->id, 'unit_id' => $unit->id, 'starts' => '2026-09-10']);
        Deployment::factory()->create(['agency_id' => $agency->id, 'unit_id' => $unit->id]);

        $this->get(route('units.index'))->assertInertia(fn (Assert $page) => $page->where('units.0.people_count', 2));
        $this->delete(route('employees.destroy', $placement->employee_id))->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('deployments', ['id' => $placement->id, 'ends' => '2026-09-10']);
        $this->assertSoftDeleted('employees', ['id' => $placement->employee_id]);
        $this->assertNull(Employee::withTrashed()->findOrFail($placement->employee_id)->currentDeployment);
        $this->get(route('units.index'))->assertInertia(fn (Assert $page) => $page
            ->where('units.0.people_count', 1)->where('units.0.deployments_count', 2));
    }

    public function test_removal_deletes_a_future_placement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 00:30:00'));
        $placement = Deployment::factory()->create(['starts' => '2026-09-11']);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);

        $this->delete(route('employees.destroy', $placement->employee_id))->assertRedirect()->assertSessionHas('success');

        $this->assertModelMissing($placement);
        $this->assertSoftDeleted('employees', ['id' => $placement->employee_id]);
        $this->get(route('units.index'))->assertInertia(fn (Assert $page) => $page
            ->where('units.0.people_count', 0)->where('units.0.deployments_count', 0));
    }

    public function test_removal_preserves_closed_placement_history(): void
    {
        $placement = Deployment::factory()->closed()->create(['starts' => '2024-01-01', 'ends' => '2024-12-31']);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);

        $this->delete(route('employees.destroy', $placement->employee_id))->assertRedirect()->assertSessionHas('success');

        $this->assertSame('2024-12-31', $placement->fresh()->ends->toDateString());
        $this->assertSoftDeleted('employees', ['id' => $placement->employee_id]);
    }
}
