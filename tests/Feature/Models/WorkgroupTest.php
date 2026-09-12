<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Employee;
use App\Models\Workgroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkgroupTest extends TestCase
{
    private function workgroupRow(string $agencyId, array $overrides = []): array
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

    public function test_workgroup_needs_an_agency(): void
    {
        $this->assertDatabaseRefuses('23502', fn () => Workgroup::factory()->create(['agency_id' => null]));
    }

    public function test_agency_id_must_reference_an_existing_agency(): void
    {
        $this->assertDatabaseRefuses('23503', fn () => DB::table('workgroups')->insert(
            $this->workgroupRow((string) Str::ulid()) // no such agency
        ));
    }

    public function test_agency_with_workgroups_cannot_be_deleted(): void
    {
        $agency = Agency::factory()->create();
        Workgroup::factory()->create(['agency_id' => $agency->id]);

        $this->assertDatabaseRefuses('23001', fn () => DB::table('agencies')->where('id', $agency->id)->delete());
    }

    public function test_id_and_agency_id_pair_is_unique(): void
    {
        $workgroup = Workgroup::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('workgroups')->insert(
            $this->workgroupRow($workgroup->agency_id, ['id' => $workgroup->id])
        ));

        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'workgroups_id_agency_id_unique'"));
    }

    public function test_parent_id_must_share_the_workgroups_agency(): void
    {
        $other = Workgroup::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Workgroup::factory()->create(['parent_id' => $other->id]));
    }

    public function test_parent_with_children_cannot_be_deleted(): void
    {
        $parent = Workgroup::factory()->create();
        Workgroup::factory()->under($parent)->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('workgroups')->where('id', $parent->id)->delete());
    }

    public function test_head_id_must_share_the_workgroups_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Workgroup::factory()->create(['head_id' => $employee->id]));
    }

    public function test_head_cannot_be_hard_deleted(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Workgroup::factory()->create(['agency_id' => $agency->id, 'head_id' => $employee->id]);

        $this->assertDatabaseRefuses('23001', fn () => $employee->forceDelete());
    }

    public function test_code_is_unique_per_agency(): void
    {
        $agency = Agency::factory()->create();
        Workgroup::factory()->create(['agency_id' => $agency->id, 'code' => 'HR']);

        $this->assertDatabaseRefuses('23505', fn () => Workgroup::factory()->create(['agency_id' => $agency->id, 'code' => 'HR']));

        // Same code, a different agency: accepted.
        $elsewhere = Workgroup::factory()->create(['code' => 'HR']);
        $this->assertDatabaseHas('workgroups', ['id' => $elsewhere->id, 'code' => 'HR']);
    }

    public function test_a_workgroup_cannot_be_its_own_parent(): void
    {
        $agency = Agency::factory()->create();
        $id = (string) Str::ulid();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('workgroups')->insert(
            $this->workgroupRow($agency->id, ['id' => $id, 'parent_id' => $id])
        ));

        $workgroup = Workgroup::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('workgroups')->where('id', $workgroup->id)->update(['parent_id' => $workgroup->id]));
    }

    public function test_repointing_a_workgroup_under_its_own_descendant_is_refused(): void
    {
        $a = Workgroup::factory()->create();
        $b = Workgroup::factory()->under($a)->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workgroups')->where('id', $a->id)->update(['parent_id' => $b->id]));
    }

    public function test_a_multi_row_insert_cannot_close_a_cycle(): void
    {
        $agency = Agency::factory()->create();
        $x = (string) Str::ulid();
        $y = (string) Str::ulid();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workgroups')->insert([
            $this->workgroupRow($agency->id, ['id' => $x, 'parent_id' => $y]),
            $this->workgroupRow($agency->id, ['id' => $y, 'parent_id' => $x]),
        ]));
    }

    public function test_a_workgroup_cannot_belong_to_the_platform_agency(): void
    {
        $platform = $this->platform();

        $this->assertDatabaseRefuses('P0001', fn () => Workgroup::factory()->create(['agency_id' => $platform->id]));

        $workgroup = Workgroup::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workgroups')->where('id', $workgroup->id)->update(['agency_id' => $platform->id]));
    }

    public function test_descendants_and_ancestors_walk_the_tree(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);

        $root = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $child = Workgroup::factory()->under($root)->create();
        $grandchild = Workgroup::factory()->under($child)->create();
        $sibling = Workgroup::factory()->under($root)->create();
        $otherRoot = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $otherChild = Workgroup::factory()->under($otherRoot)->create();

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
