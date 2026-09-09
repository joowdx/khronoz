<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
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
