<?php

namespace Tests\Feature\Actions;

use App\Actions\ReassignEmployee;
use App\Actions\TransferEmployee;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ReassignEmployeeTest extends TestCase
{
    public function test_reassignment_leaves_the_substantive_placement_open(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $home = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $elsewhere = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $placement = app(TransferEmployee::class)->handle($employee, $home, Carbon::parse('2026-01-01'));

        $reassignment = app(ReassignEmployee::class)->handle(
            $employee->fresh(), $elsewhere, Carbon::parse('2026-03-01'), Carbon::parse('2026-05-31')
        );

        $this->assertSame($placement->id, $reassignment->parent_id);
        $this->assertNull($placement->fresh()->ends, 'the placement must stay open — the item never left');
        $this->assertSame($elsewhere->id, $reassignment->workgroup_id);
        $this->assertSame('2026-05-31', $reassignment->ends->toDateString());

        // Both rows are open-ended facts about the same person on the same
        // day, which is exactly what the partitioned exclusion constraints
        // exist to allow.
        $this->assertSame(2, $employee->deployments()->count());
    }

    public function test_a_reassignment_may_be_open_ended(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $home = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $elsewhere = Workgroup::factory()->create(['agency_id' => $agency->id]);

        app(TransferEmployee::class)->handle($employee, $home, Carbon::parse('2026-01-01'));
        $reassignment = app(ReassignEmployee::class)->handle($employee->fresh(), $elsewhere, Carbon::parse('2026-03-01'));

        $this->assertNull($reassignment->ends);
        $this->assertNotNull($reassignment->parent_id);
    }

    public function test_refuses_to_reassign_an_employee_with_no_open_placement(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $elsewhere = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $this->expectException(RuntimeException::class);

        try {
            app(ReassignEmployee::class)->handle($employee, $elsewhere, Carbon::parse('2026-03-01'));
        } finally {
            $this->assertSame(0, $employee->deployments()->count(), 'nothing may be written');
        }
    }

    public function test_a_closed_placement_is_not_a_reassignment_parent(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2025-01-01', 'ends' => '2025-12-31']);
        $this->withTenant(Agency::findOrFail($placement->agency_id));
        $elsewhere = Workgroup::factory()->create(['agency_id' => $placement->agency_id]);

        $this->expectException(RuntimeException::class);

        app(ReassignEmployee::class)->handle($placement->employee, $elsewhere, Carbon::parse('2025-06-01'));
    }

    public function test_refuses_a_reassignment_that_starts_before_its_placement(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $this->withTenant(Agency::findOrFail($placement->agency_id));
        $elsewhere = Workgroup::factory()->create(['agency_id' => $placement->agency_id]);

        $this->assertDatabaseRefuses('P0001', fn () => app(ReassignEmployee::class)->handle(
            $placement->employee, $elsewhere, Carbon::parse('2025-06-01'), Carbon::parse('2025-12-31')
        ));
    }

    public function test_refuses_an_open_ended_reassignment_inside_a_fixed_term_placement(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01'));
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => '2026-06-30']);
        $this->withTenant(Agency::findOrFail($placement->agency_id));
        $elsewhere = Workgroup::factory()->create(['agency_id' => $placement->agency_id]);

        $this->assertDatabaseRefuses('P0001', fn () => app(ReassignEmployee::class)->handle(
            $placement->employee, $elsewhere, Carbon::parse('2026-04-01')
        ));

        // The same reassignment, ending inside the placement: accepted.
        $accepted = app(ReassignEmployee::class)->handle(
            $placement->employee->fresh(), $elsewhere, Carbon::parse('2026-04-01'), Carbon::parse('2026-05-31')
        );

        $this->assertSame($placement->id, $accepted->parent_id);
    }

    public function test_refuses_a_second_overlapping_reassignment(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $this->withTenant(Agency::findOrFail($placement->agency_id));
        $elsewhere = Workgroup::factory()->create(['agency_id' => $placement->agency_id]);

        app(ReassignEmployee::class)->handle($placement->employee, $elsewhere, Carbon::parse('2026-03-01'), Carbon::parse('2026-05-31'));

        $this->assertDatabaseRefuses('23P01', fn () => app(ReassignEmployee::class)->handle(
            $placement->employee->fresh(), $elsewhere, Carbon::parse('2026-05-01'), Carbon::parse('2026-07-31')
        ));
    }

    public function test_a_refused_reassignment_writes_nothing(): void
    {
        // The foreign workgroup is created before the tenant is set:
        // BelongsToAgency refuses to create a row for any agency but the
        // current one, which is the protection working, not a test obstacle.
        $foreign = Workgroup::factory()->create();
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $this->withTenant(Agency::findOrFail($placement->agency_id));

        $before = DB::table('deployments')->count();

        $this->assertDatabaseRefuses('23503', fn () => app(ReassignEmployee::class)->handle(
            $placement->employee, $foreign, Carbon::parse('2026-03-01')
        ));

        $this->assertSame($before, DB::table('deployments')->count());
    }
}
