<?php

namespace Tests\Feature\Jobs;

use App\Jobs\FanOutRecompute;
use App\Jobs\RecomputeWorkdays;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Workday;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The calendar half of Workday rule 3 (decision 86).
 *
 * Every test here is about *whom* and *over what*, never about arithmetic —
 * what the recompute then produces is `ComputerTest`'s subject. The rule
 * being held is that a calendar change refreshes computed days and creates
 * none.
 */
class FanOutRecomputeTest extends TestCase
{
    public function test_it_queues_one_recompute_for_each_employee_with_a_computed_day(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $first = $this->worked($agency, '2026-09-10');
        $second = $this->worked($agency, '2026-09-10');

        Queue::fake([RecomputeWorkdays::class]);

        (new FanOutRecompute('2026-09-01', '2026-09-30', $agency->id))->handle(app(Tenant::class));

        Queue::assertPushedTimes(RecomputeWorkdays::class, 2);
        $this->assertQueued($first->id, '2026-09-10', '2026-09-10');
        $this->assertQueued($second->id, '2026-09-10', '2026-09-10');
    }

    public function test_the_span_is_clamped_to_the_days_actually_computed(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = $this->worked($agency, '2026-09-08');
        $this->workday($agency, $employee, '2026-09-20');

        Queue::fake([RecomputeWorkdays::class]);

        (new FanOutRecompute('2026-09-01', '2026-09-30', $agency->id))->handle(app(Tenant::class));

        Queue::assertPushedTimes(RecomputeWorkdays::class, 1);
        $this->assertQueued($employee->id, '2026-09-08', '2026-09-20');
    }

    public function test_an_employee_with_no_computed_day_in_the_span_is_left_alone(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $this->worked($agency, '2026-09-10');

        Queue::fake([RecomputeWorkdays::class]);

        (new FanOutRecompute('2026-12-21', '2026-12-27', $agency->id))->handle(app(Tenant::class));

        Queue::assertNotPushed(RecomputeWorkdays::class);
    }

    public function test_a_day_after_the_span_is_not_reached(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = $this->worked($agency, '2026-09-10');
        $this->workday($agency, $employee, '2026-10-05');

        Queue::fake([RecomputeWorkdays::class]);

        (new FanOutRecompute('2026-09-01', '2026-09-30', $agency->id))->handle(app(Tenant::class));

        $this->assertQueued($employee->id, '2026-09-10', '2026-09-10');
    }

    public function test_an_agency_fan_out_does_not_reach_another_agency(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $ours = $this->worked($agency, '2026-09-10');

        $other = Agency::factory()->create();
        $this->withTenant($other);
        $theirs = $this->worked($other, '2026-09-10');

        Queue::fake([RecomputeWorkdays::class]);

        (new FanOutRecompute('2026-09-01', '2026-09-30', $agency->id))->handle(app(Tenant::class));

        Queue::assertPushedTimes(RecomputeWorkdays::class, 1);
        $this->assertQueued($ours->id, '2026-09-10', '2026-09-10');
        Queue::assertNotPushed(
            RecomputeWorkdays::class,
            fn (RecomputeWorkdays $job): bool => $job->employeeId === $theirs->id,
        );
    }

    public function test_a_platform_holiday_reaches_every_agency(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $ours = $this->worked($agency, '2026-09-10');

        $other = Agency::factory()->create();
        $this->withTenant($other);
        $theirs = $this->worked($other, '2026-09-10');

        Queue::fake([RecomputeWorkdays::class]);

        (new FanOutRecompute('2026-09-01', '2026-09-30'))->handle(app(Tenant::class));

        Queue::assertPushedTimes(RecomputeWorkdays::class, 2);
        $this->assertQueued($ours->id, '2026-09-10', '2026-09-10');
        $this->assertQueued($theirs->id, '2026-09-10', '2026-09-10');
    }

    public function test_an_open_ended_span_runs_to_the_last_computed_day(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = $this->worked($agency, '2026-09-10');
        $this->workday($agency, $employee, '2026-10-05');

        Queue::fake([RecomputeWorkdays::class]);

        (new FanOutRecompute('2026-09-01', null, $agency->id))->handle(app(Tenant::class));

        $this->assertQueued($employee->id, '2026-09-10', '2026-10-05');
    }

    public function test_it_puts_back_the_tenant_it_found(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $this->worked($agency, '2026-09-10');

        Queue::fake([RecomputeWorkdays::class]);

        (new FanOutRecompute('2026-09-01', '2026-09-30', $agency->id))->handle(app(Tenant::class));

        $this->assertSame($agency->id, app(Tenant::class)->id());
    }

    public function test_it_leaves_no_tenant_behind_when_it_found_none(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $this->worked($agency, '2026-09-10');

        app(Tenant::class)->forget();
        Queue::fake([RecomputeWorkdays::class]);

        (new FanOutRecompute('2026-09-01', '2026-09-30', $agency->id))->handle(app(Tenant::class));

        $this->assertNull(app(Tenant::class)->id());
    }

    public function test_naming_nobody_queues_nothing(): void
    {
        Queue::fake([FanOutRecompute::class]);

        FanOutRecompute::forEmployees([], '2026-09-01', '2026-09-30');

        Queue::assertNotPushed(FanOutRecompute::class);
    }

    public function test_naming_employees_narrows_to_them(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $named = $this->worked($agency, '2026-09-10');
        $bystander = $this->worked($agency, '2026-09-10');

        Queue::fake([RecomputeWorkdays::class]);

        (new FanOutRecompute('2026-09-01', '2026-09-30', null, [$named->id]))->handle(app(Tenant::class));

        Queue::assertPushedTimes(RecomputeWorkdays::class, 1);
        Queue::assertNotPushed(
            RecomputeWorkdays::class,
            fn (RecomputeWorkdays $job): bool => $job->employeeId === $bystander->id,
        );
    }

    public function test_a_removed_employee_is_still_reached(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = $this->worked($agency, '2026-09-10');
        $employee->delete();

        Queue::fake([RecomputeWorkdays::class]);

        (new FanOutRecompute('2026-09-01', '2026-09-30', $agency->id))->handle(app(Tenant::class));

        $this->assertQueued($employee->id, '2026-09-10', '2026-09-10');
    }

    private function assertQueued(string $employeeId, string $from, string $to): void
    {
        Queue::assertPushed(
            RecomputeWorkdays::class,
            fn (RecomputeWorkdays $job): bool => $job->employeeId === $employeeId
                && $job->from === $from
                && $job->to === $to,
        );
    }

    private function worked(Agency $agency, string $date): Employee
    {
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->workday($agency, $employee, $date);

        return $employee;
    }

    private function workday(Agency $agency, Employee $employee, string $date): Workday
    {
        $ledger = Ledger::firstOrCreate([
            'employee_id' => $employee->id,
            'month' => CarbonImmutable::parse($date)->startOfMonth()->toDateString(),
        ], ['agency_id' => $agency->id]);

        return Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'ledger_id' => $ledger->id,
            'date' => $date,
        ]);
    }
}
