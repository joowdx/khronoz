<?php

namespace Tests\Feature\Attendance;

use App\Attendance\LedgerSnapshot;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Punch;
use App\Models\Workday;
use Tests\TestCase;

class LedgerSnapshotTest extends TestCase
{
    public function test_freezes_only_the_four_outer_month_boundary_candidates_as_context(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->for($agency)->create();
        $ledger = new Ledger([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'starts' => '2026-07-01',
            'ends' => '2026-07-31',
        ]);
        $outerWorkday = Workday::factory()->for($agency)->for($employee)->create(['date' => '2026-06-30']);
        Punch::factory()->for($agency)->for($employee)->for($outerWorkday)->create([
            'expected_at' => '2026-06-30 19:00:00',
            'actual_at' => '2026-06-30 19:00:00',
        ]);
        Workday::factory()->for($agency)->for($employee)->create(['date' => '2026-06-28']);

        $calculation = app(LedgerSnapshot::class)->capture($ledger)['calculation'];

        $this->assertSame(['2026-06-30'], collect($calculation['boundaries'])->pluck('date')->all());
        $this->assertSame('19:00:00', substr($calculation['boundaries'][0]['punches'][0]['actual_at'], 11, 8));
    }
}
