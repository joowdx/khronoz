<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Employee;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnitTest extends TestCase
{
    /** Baseline row for a raw insert, every real column set explicitly. */
    private function unitRow(string $agencyId, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'parent_id' => null,
            'kind' => null,
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'name' => 'X',
            'head_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    public function test_unit_needs_an_agency(): void
    {
        $this->assertDatabaseRefuses('23502', fn () => Unit::factory()->create(['agency_id' => null]));
    }

    /** units_agency_id_foreign, insert side. parent_id/head_id null so no other FK competes. */
    public function test_agency_id_must_reference_an_existing_agency(): void
    {
        $this->assertDatabaseRefuses('23503', fn () => DB::table('units')->insert(
            $this->unitRow((string) Str::ulid()) // no such agency
        ));
    }

    /**
     * units_agency_id_foreign, delete side. Must be a non-platform agency —
     * deleting the platform row itself trips agencies_platform_row first
     * (P0001), proving nothing about this FK (AgencyTest documents the trap).
     */
    public function test_agency_with_units_cannot_be_deleted(): void
    {
        $agency = Agency::factory()->create();
        Unit::factory()->create(['agency_id' => $agency->id]);

        $this->assertDatabaseRefuses('23001', fn () => DB::table('agencies')->where('id', $agency->id)->delete());
    }

    public function test_id_and_agency_id_pair_is_unique(): void
    {
        $unit = Unit::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('units')->insert(
            $this->unitRow($unit->agency_id, ['id' => $unit->id])
        ));
    }

    /**
     * units_parent_id_agency_id_foreign, insert side. $other belongs to its
     * own (different, factory-generated) agency, so this is a cross-agency
     * parent, not a nonexistent one — only a paired FK fails on that.
     */
    public function test_parent_id_must_share_the_units_agency(): void
    {
        $other = Unit::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Unit::factory()->create(['parent_id' => $other->id]));
    }

    /** units_parent_id_agency_id_foreign, delete side: a parent that still has a child. */
    public function test_parent_with_children_cannot_be_deleted(): void
    {
        $parent = Unit::factory()->create();
        Unit::factory()->under($parent)->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('units')->where('id', $parent->id)->delete());
    }

    /** units_head_id_agency_id_foreign, insert side: an employee of a different agency. */
    public function test_head_id_must_share_the_units_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Unit::factory()->create(['head_id' => $employee->id]));
    }

    /**
     * units_head_id_agency_id_foreign, delete side. Employees are soft
     * deleted, so $employee->delete() is an UPDATE the FK never sees —
     * forceDelete() is the real DELETE the RESTRICT action can refuse.
     */
    public function test_head_cannot_be_hard_deleted(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Unit::factory()->create(['agency_id' => $agency->id, 'head_id' => $employee->id]);

        $this->assertDatabaseRefuses('23001', fn () => $employee->forceDelete());
    }

    public function test_code_is_unique_per_agency(): void
    {
        $agency = Agency::factory()->create();
        Unit::factory()->create(['agency_id' => $agency->id, 'code' => 'HR']);

        $this->assertDatabaseRefuses('23505', fn () => Unit::factory()->create(['agency_id' => $agency->id, 'code' => 'HR']));

        // Same code, a different agency: accepted.
        $elsewhere = Unit::factory()->create(['code' => 'HR']);
        $this->assertDatabaseHas('units', ['id' => $elsewhere->id, 'code' => 'HR']);
    }

    /**
     * units_parent_not_self, both INSERT and UPDATE — both raise 23514, not
     * P0001: the CHECK wins before units_acyclic's AFTER trigger ever runs,
     * on both statement kinds (confirmed against the live schema, Task 2).
     * A self-parent row alone would pass with units_acyclic entirely absent;
     * see test_repointing_a_unit_under_its_own_descendant_is_refused below
     * for the trigger's own violation.
     */
    public function test_a_unit_cannot_be_its_own_parent(): void
    {
        $agency = Agency::factory()->create();
        $id = (string) Str::ulid();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('units')->insert(
            $this->unitRow($agency->id, ['id' => $id, 'parent_id' => $id])
        ));

        $unit = Unit::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('units')->where('id', $unit->id)->update(['parent_id' => $unit->id]));
    }

    /**
     * units_acyclic via UPDATE — the only way an INSERT-safe cycle becomes
     * reachable: A and B both insert cleanly (B under A), then repointing A
     * under its own descendant B closes the cycle.
     */
    public function test_repointing_a_unit_under_its_own_descendant_is_refused(): void
    {
        $a = Unit::factory()->create();
        $b = Unit::factory()->under($a)->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('units')->where('id', $a->id)->update(['parent_id' => $b->id]));
    }

    /**
     * units_acyclic via one multi-row INSERT. units_acyclic is AFTER INSERT
     * ... DEFERRABLE INITIALLY IMMEDIATE precisely so a statement producing
     * several mutually-parented rows at once still gets caught — a per-row
     * BEFORE trigger could not see the sibling row this needs.
     */
    public function test_a_multi_row_insert_cannot_close_a_cycle(): void
    {
        $agency = Agency::factory()->create();
        $x = (string) Str::ulid();
        $y = (string) Str::ulid();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('units')->insert([
            $this->unitRow($agency->id, ['id' => $x, 'parent_id' => $y]),
            $this->unitRow($agency->id, ['id' => $y, 'parent_id' => $x]),
        ]));
    }

    /** agency_not_platform on units: neither an INSERT under the platform agency nor an UPDATE into it is allowed. */
    public function test_a_unit_cannot_belong_to_the_platform_agency(): void
    {
        $platform = $this->platform();

        $this->assertDatabaseRefuses('P0001', fn () => Unit::factory()->create(['agency_id' => $platform->id]));

        $unit = Unit::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('units')->where('id', $unit->id)->update(['agency_id' => $platform->id]));
    }

    /**
     * Permanent version of Task 3's ad hoc tinker proof: a three-level tree
     * plus a deliberately unrelated branch, in one agency.
     *
     *   ROOT
     *     CHILD
     *       GRANDCHILD
     *     SIBLING
     *   OTHER_ROOT
     *     OTHER_CHILD
     */
    public function test_descendants_and_ancestors_walk_the_tree(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);

        $root = Unit::factory()->create(['agency_id' => $agency->id]);
        $child = Unit::factory()->under($root)->create();
        $grandchild = Unit::factory()->under($child)->create();
        $sibling = Unit::factory()->under($root)->create();
        $otherRoot = Unit::factory()->create(['agency_id' => $agency->id]);
        $otherChild = Unit::factory()->under($otherRoot)->create();

        $this->assertEqualsCanonicalizing(
            [$child->id, $grandchild->id, $sibling->id],
            $root->descendants()->pluck('id')->all(),
        );

        $this->assertEqualsCanonicalizing(
            [$grandchild->id],
            $child->descendants()->pluck('id')->all(),
        );

        $this->assertEqualsCanonicalizing(
            [$otherChild->id],
            $otherRoot->descendants()->pluck('id')->all(),
        );

        $this->assertEqualsCanonicalizing(
            [$child->id, $root->id],
            $grandchild->ancestors()->pluck('id')->all(),
        );
    }
}
