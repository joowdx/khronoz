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

class UnitControllerTest extends TestCase
{
    #[DataProvider('permissionsWithoutOrganizationView')]
    public function test_units_without_organization_view_are_forbidden_from_index(Permission $permission): void
    {
        $this->actingAsAgency(Agency::factory()->create(), $permission);

        $this->get(route('units.index'))->assertForbidden();
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
            'create' => ['get', 'units.create', false],
            'store' => ['post', 'units.store', false],
            'edit' => ['get', 'units.edit', true],
            'update' => ['put', 'units.update', true],
            'destroy' => ['delete', 'units.destroy', true],
        ];
    }

    #[DataProvider('organizationManagementRouteCases')]
    public function test_view_only_is_forbidden_from_every_unit_management_route(string $verb, string $routeName, bool $needsUnit): void
    {
        $agency = Agency::factory()->create();
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $url = $needsUnit ? route($routeName, $unit) : route($routeName);

        $this->{$verb}($url)->assertForbidden();
    }

    /**
     * Flat with parent_id, not nested (task-6-brief.md — the tree is built
     * client side). Two units plus a head, mirroring the same
     * "two-or-more rows" caution as EmployeeControllerTest even though
     * UnitController::index already eager-loads `head`.
     */
    /**
     * The tree's two aggregates. `people_count` is the headcount to show —
     * open deployments only — and `deployments_count` is every placement the
     * unit has ever held, which is what decides whether Remove can be offered
     * at all: `deployments_unit_id_agency_id_foreign` RESTRICTs, and its
     * refusal arrives as a 500 rather than as a message a form can show.
     *
     * The fixture keeps them apart deliberately: one unit with a closed
     * deployment and no open one must read 0 people but 1 placement, so a
     * single count could not stand in for both.
     *
     * Three roots, so the two meanings of `people_count` — the unit's own and
     * its subtree's — coincide here; the subtree half is
     * test_the_headcount_covers_everything_under_the_unit below.
     */
    public function test_index_counts_who_is_in_each_unit_now_and_who_ever_was(): void
    {
        $agency = Agency::factory()->create();
        $busy = Unit::factory()->create(['agency_id' => $agency->id, 'name' => 'Aaa Busy']);
        $vacated = Unit::factory()->create(['agency_id' => $agency->id, 'name' => 'Mmm Vacated']);
        Unit::factory()->create(['agency_id' => $agency->id, 'name' => 'Zzz Untouched']);

        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Deployment::factory()->create([
            'agency_id' => $agency->id, 'employee_id' => $employee->id, 'unit_id' => $vacated->id,
            'starts' => '2020-01-01', 'ends' => '2021-12-31',
        ]);
        Deployment::factory()->create([
            'agency_id' => $agency->id, 'employee_id' => $employee->id, 'unit_id' => $busy->id,
            'starts' => '2022-01-01', 'ends' => null,
        ]);

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('units.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('units', 3)
                ->where('units.0.people_count', 1)
                ->where('units.0.deployments_count', 1)
                ->where('units.1.people_count', 0)
                ->where('units.1.deployments_count', 1)
                ->where('units.2.people_count', 0)
                ->where('units.2.deployments_count', 0));
    }

    /**
     * `people_count` is the subtree's headcount, not the unit's own row's.
     * The row labels it that way and links there — `aria-label` reads "N in
     * Administrative Division and below" and the number is a link to
     * `/employees?unit=…`, whose filter expands over Unit::descendants()
     * (01-organization.md rule 4) — so the number has to be counted the same
     * way. MEASURED against the seeded Demo Agency before UnitController
     * rolled it up: Administrative Division displayed 6 while its own link
     * reported 15.
     *
     * The fixture is three levels deep and every unit holds people of its
     * own — 1, 2 and 3 — so the rollup is load-bearing twice over: without it
     * the department reads 1, and with a one-level "sum of my children" it
     * reads 3. Only a transitive rollup answers 6.
     *
     * One employee per deployment: `deployments_no_overlap` allows a person
     * exactly one open placement, so a headcount of six needs six people.
     */
    public function test_the_headcount_covers_everything_under_the_unit(): void
    {
        $agency = Agency::factory()->create();
        $department = Unit::factory()->create(['agency_id' => $agency->id, 'name' => 'Aaa Department']);
        $division = Unit::factory()->under($department)->create(['name' => 'Bbb Division']);
        $section = Unit::factory()->under($division)->create(['name' => 'Ccc Section']);

        foreach ([$department->id => 1, $division->id => 2, $section->id => 3] as $unitId => $people) {
            for ($i = 0; $i < $people; $i++) {
                Deployment::factory()->create([
                    'agency_id' => $agency->id,
                    'employee_id' => Employee::factory()->create(['agency_id' => $agency->id])->id,
                    'unit_id' => $unitId,
                    'starts' => '2022-01-01',
                    'ends' => null,
                ]);
            }
        }

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('units.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('units', 3)
                // 1 of its own, 5 below it.
                ->where('units.0.people_count', 6)
                ->where('units.1.people_count', 5)
                ->where('units.2.people_count', 3)
                // Never rolled up: this one guards Remove against a RESTRICT
                // on the unit's own deployment rows.
                ->where('units.0.deployments_count', 1)
                ->where('units.1.deployments_count', 2)
                ->where('units.2.deployments_count', 3));
    }

    /**
     * The head picker's options, on the index and on both forms. Someone who
     * has left cannot run a unit, so offering them would be offering a
     * mistake — and the list is this tenant's own, like everything else here.
     */
    public function test_the_head_picker_offers_only_this_agency_s_employees_who_are_still_employed(): void
    {
        $agency = Agency::factory()->create();
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);
        $employed = Employee::factory()->create(['agency_id' => $agency->id, 'separated_at' => null]);
        // The factory's own state, not a hard-coded date: `hired_at` is random,
        // and employees_separation_after_hire refuses a separation before it.
        Employee::factory()->separated()->create(['agency_id' => $agency->id]);
        Employee::factory()->create(); // another agency entirely

        $this->actingAsAgency($agency, Permission::ManageOrganization);

        foreach ([route('units.index'), route('units.create'), route('units.edit', $unit)] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('employees', 1)
                    ->where('employees.0.id', $employed->id));
        }
    }

    /** Both forms need the whole tree for their parent picker; the front end composes the nesting from parent_id. */
    public function test_the_forms_carry_the_tree_for_their_parent_picker(): void
    {
        $agency = Agency::factory()->create();
        $parent = Unit::factory()->create(['agency_id' => $agency->id]);
        Unit::factory()->under($parent)->create();
        Unit::factory()->create(); // another agency entirely

        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->get(route('units.create'))
            ->assertInertia(fn (Assert $page) => $page->component('units/create', false)->has('units', 2));

        $this->get(route('units.edit', $parent))
            ->assertInertia(fn (Assert $page) => $page->component('units/edit', false)
                ->where('unit.id', $parent->id)
                ->has('units', 2));
    }

    public function test_index_lists_every_unit_of_the_current_agency_flat_with_its_head(): void
    {
        $agency = Agency::factory()->create();
        $head = Employee::factory()->create(['agency_id' => $agency->id]);
        $parent = Unit::factory()->create(['agency_id' => $agency->id, 'name' => 'Aaa Root', 'head_id' => $head->id]);
        $child = Unit::factory()->under($parent)->create(['name' => 'Zzz Child']);
        Unit::factory()->create(); // another agency entirely

        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->get(route('units.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('units/index', false)
                ->has('units', 2)
                ->where('units.0.id', $parent->id)
                ->where('units.0.head.id', $head->id)
                ->where('units.1.id', $child->id)
                ->where('units.1.parent_id', $parent->id));
    }

    /**
     * Minor 6: create/edit were only ever exercised for 403 (view-only) and
     * 404 (cross-tenant), never for a manager actually reaching the form —
     * so a wrong Inertia::render() component string here would first surface
     * in the next task's screens, not in this suite.
     */
    public function test_create_renders_the_unit_create_form(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->get(route('units.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('units/create', false));
    }

    public function test_store_creates_a_unit(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('units.store'), ['code' => 'HR', 'name' => 'Human Resources'])
            ->assertRedirect(route('units.index'))->assertSessionHas('success');

        $unit = Unit::withoutGlobalScopes()->where('code', 'HR')->firstOrFail();
        $this->assertSame($agency->id, $unit->agency_id);
    }

    public function test_store_requires_a_unique_code_per_agency(): void
    {
        $agency = Agency::factory()->create();
        Unit::factory()->create(['agency_id' => $agency->id, 'code' => 'HR']);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('units.store'), ['code' => 'HR', 'name' => 'Duplicate'])
            ->assertSessionHasErrors('code');
    }

    /**
     * Minor 5: StoreUnitRequest upper-cases code in prepareForValidation()
     * before the uniqueness rule runs (mirrors StoreAgencyRequest), so a
     * lower-case 'hr' must still collide with an existing 'HR' — the
     * database's own unique index is case-sensitive and would not catch it.
     */
    public function test_store_requires_a_unique_code_per_agency_case_insensitively(): void
    {
        $agency = Agency::factory()->create();
        Unit::factory()->create(['agency_id' => $agency->id, 'code' => 'HR']);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('units.store'), ['code' => 'hr', 'name' => 'Duplicate'])
            ->assertSessionHasErrors(['code' => 'Already taken']);
    }

    public function test_store_requires_the_parent_to_belong_to_the_current_agency(): void
    {
        $foreignParent = Unit::factory()->create();
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('units.store'), ['code' => 'HR', 'name' => 'Human Resources', 'parent_id' => $foreignParent->id])
            ->assertSessionHasErrors('parent_id');
    }

    /**
     * Minor 6: also proves UnitController::edit's $unit->load('head') —
     * entirely unexercised before, since only the 403/404 cases were tested.
     */
    public function test_edit_renders_the_unit_being_edited_with_its_head(): void
    {
        $agency = Agency::factory()->create();
        $head = Employee::factory()->create(['agency_id' => $agency->id]);
        $unit = Unit::factory()->create(['agency_id' => $agency->id, 'head_id' => $head->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->get(route('units.edit', $unit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('units/edit', false)
                ->where('unit.id', $unit->id)
                ->where('unit.head.id', $head->id));
    }

    public function test_update_persists_changes(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $unit = Unit::factory()->create(['agency_id' => $agency->id, 'name' => 'Old']);

        $this->put(route('units.update', $unit), ['code' => $unit->code, 'name' => 'New'])
            ->assertRedirect(route('units.index'))->assertSessionHas('success');

        $this->assertSame('New', $unit->fresh()->name);
    }

    /** Minor 5: mirrors the store-side case-insensitivity test — UpdateUnitRequest normalises the same way. */
    public function test_update_requires_a_unique_code_per_agency_case_insensitively(): void
    {
        $agency = Agency::factory()->create();
        Unit::factory()->create(['agency_id' => $agency->id, 'code' => 'HR']);
        $unit = Unit::factory()->create(['agency_id' => $agency->id, 'code' => 'FIN']);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->put(route('units.update', $unit), ['code' => 'hr', 'name' => $unit->name])
            ->assertSessionHasErrors(['code' => 'Already taken']);
    }

    public function test_destroy_removes_the_unit(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);

        $this->delete(route('units.destroy', $unit))
            ->assertRedirect(route('units.index'))->assertSessionHas('success');

        $this->assertModelMissing($unit);
    }

    /**
     * `units_acyclic` (a CONSTRAINT TRIGGER, P0001) is the only thing that
     * decides this, and the controller translates its refusal — see
     * UnitController::update. Reachable from a stale edit page: open Edit for
     * A, move B under A in another tab, then set A's parent to B. Before the
     * translation this was an uncaught 500.
     */
    public function test_update_refuses_a_parent_that_is_one_of_the_units_own(): void
    {
        $agency = Agency::factory()->create();
        $parent = Unit::factory()->create(['agency_id' => $agency->id]);
        $child = Unit::factory()->under($parent)->create();
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->put(route('units.update', $parent), [
            'code' => $parent->code,
            'name' => $parent->name,
            'parent_id' => $child->id,
        ])->assertSessionHasErrors(['parent_id' => 'Under one of its own units.']);

        $this->assertNull($parent->fresh()->parent_id);
    }

    /** units_parent_not_self, a CHECK (23514). The picker excludes the unit from its own options, so this arrives by URL. */
    public function test_update_refuses_a_unit_as_its_own_parent(): void
    {
        $agency = Agency::factory()->create();
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->put(route('units.update', $unit), [
            'code' => $unit->code,
            'name' => $unit->name,
            'parent_id' => $unit->id,
        ])->assertSessionHasErrors(['parent_id' => 'Cannot be its own parent.']);

        $this->assertNull($unit->fresh()->parent_id);
    }

    /**
     * The two real RESTRICTs on a unit's own delete. The tree hides Remove
     * where either would bite, but the route stays authorized and reachable
     * from a stale index page or by URL, so the refusal is translated into a
     * flash error rather than a 500. There is no field to hang it on.
     *
     * The wording itself is pinned below (fix round 2): it is the only one of
     * the five refusal messages that names the obstacle to the user, so a
     * reword, truncation or emptying of it is the one most worth catching —
     * `assertSessionHas('error')` alone would still pass for any of those.
     *
     * @return array<string, array{0: string}>
     */
    public static function undeletableUnitCases(): array
    {
        return ['a child unit' => ['child'], 'a closed deployment' => ['history']];
    }

    #[DataProvider('undeletableUnitCases')]
    public function test_destroy_reports_what_is_still_in_the_unit(string $holder): void
    {
        $agency = Agency::factory()->create();
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);

        if ($holder === 'child') {
            Unit::factory()->under($unit)->create();
        } else {
            Deployment::factory()->create([
                'agency_id' => $agency->id,
                'employee_id' => Employee::factory()->create(['agency_id' => $agency->id])->id,
                'unit_id' => $unit->id,
                'starts' => '2020-01-01',
                'ends' => '2021-12-31',
            ]);
        }

        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->delete(route('units.destroy', $unit))
            ->assertRedirect(route('units.index'))
            ->assertSessionHas('error', "{$unit->name} cannot be removed while a unit sits under it or anyone has ever been deployed to it.")
            ->assertSessionMissing('success');

        $this->assertModelExists($unit);
    }

    /**
     * M4: `units_head_id_agency_id_foreign` does NOT restrict this. `head_id`
     * points *out of* `units` at an employee, so it restricts deleting the
     * employee — and that is a soft delete, so it never fires at all. Both
     * UnitController::destroy and .ai/rules/components.md used to claim
     * otherwise; this is the proof they were wrong.
     */
    public function test_destroy_removes_a_unit_that_has_a_head(): void
    {
        $agency = Agency::factory()->create();
        $head = Employee::factory()->create(['agency_id' => $agency->id]);
        $unit = Unit::factory()->create(['agency_id' => $agency->id, 'head_id' => $head->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->delete(route('units.destroy', $unit))
            ->assertRedirect(route('units.index'))->assertSessionHas('success');

        $this->assertModelMissing($unit);
        $this->assertModelExists($head);
    }

    /**
     * M5: the picker's own query offers neither a removed nor a separated
     * employee (test_the_head_picker_offers_only_this_agency_s_employees_who_
     * are_still_employed), and the request must refuse one submitted anyway.
     * Accepting it wrote a `head_id` the screen could never display: the unit
     * came back with no head at all, because ->with('head') resolves through
     * the model's own scopes and answers null.
     *
     * @return array<string, array{0: string}>
     */
    public static function ineligibleHeadCases(): array
    {
        return ['separated' => ['separated'], 'removed' => ['removed']];
    }

    #[DataProvider('ineligibleHeadCases')]
    public function test_store_refuses_a_head_who_cannot_run_a_unit(string $state): void
    {
        $agency = Agency::factory()->create();
        $head = $this->ineligibleHead($agency, $state);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('units.store'), ['code' => 'HR', 'name' => 'Human Resources', 'head_id' => $head->id])
            ->assertSessionHasErrors('head_id');

        $this->assertDatabaseMissing('units', ['code' => 'HR']);
    }

    #[DataProvider('ineligibleHeadCases')]
    public function test_update_refuses_a_head_who_cannot_run_a_unit(string $state): void
    {
        $agency = Agency::factory()->create();
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);
        $head = $this->ineligibleHead($agency, $state);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->put(route('units.update', $unit), ['code' => $unit->code, 'name' => $unit->name, 'head_id' => $head->id])
            ->assertSessionHasErrors('head_id');

        $this->assertNull($unit->fresh()->head_id);
    }

    /** Separated uses the factory's own state, since employees_separation_after_hire refuses a separation before a random hired_at. */
    private function ineligibleHead(Agency $agency, string $state): Employee
    {
        if ($state === 'separated') {
            return Employee::factory()->separated()->create(['agency_id' => $agency->id]);
        }

        $head = Employee::factory()->create(['agency_id' => $agency->id]);
        $head->delete();

        return $head;
    }

    public function test_editing_a_unit_of_another_agency_is_not_found(): void
    {
        $stranger = Unit::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->get(route('units.edit', $stranger))->assertNotFound();
    }

    public function test_updating_a_unit_of_another_agency_is_not_found(): void
    {
        $stranger = Unit::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->put(route('units.update', $stranger), ['code' => 'X', 'name' => 'X'])->assertNotFound();
    }

    public function test_destroying_a_unit_of_another_agency_is_not_found(): void
    {
        $stranger = Unit::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->delete(route('units.destroy', $stranger))->assertNotFound();
    }
}
