<?php

namespace Tests\Feature\Jobs;

use App\Enums\HolidayType;
use App\Jobs\RecomputeWorkdays;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Ledger;
use App\Models\Workday;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecomputeWorkdaysTest extends TestCase
{
    public function test_sets_the_tenant_before_computing(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        app(Tenant::class)->forget();

        (new RecomputeWorkdays($employee->id, '2026-09-08', '2026-09-08'))
            ->handle(app(Tenant::class));

        $this->assertSame($agency->id, app(Tenant::class)->id());
        $this->assertTrue(
            Workday::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', '2026-09-08')
                ->exists(),
        );
    }

    public function test_prevents_overlapping_runs_for_the_same_employee(): void
    {
        $job = new RecomputeWorkdays('emp-1', '2026-09-01', '2026-09-01');

        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('emp-1', $middleware[0]->key);
        $this->assertSame(60, $middleware[0]->releaseAfter);
        $this->assertSame(180, $middleware[0]->expiresAfter);
        $this->assertSame(5, $job->tries);
        $this->assertNotInstanceOf(ShouldBeUnique::class, $job);
    }

    public function test_a_timelog_queues_three_days_back(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        Queue::fake([RecomputeWorkdays::class]);

        RecomputeWorkdays::dispatchFor([[
            'employee_id' => $employee->id,
            'time' => '2026-09-01 08:01:23',
        ]]);

        Queue::assertPushed(RecomputeWorkdays::class, function (RecomputeWorkdays $job) use ($employee): bool {
            return $job->employeeId === $employee->id
                && $job->from === '2026-08-29'
                && $job->to === '2026-09-01';
        });
    }

    public function test_several_punches_for_one_employee_queue_one_job_over_the_union(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        Queue::fake([RecomputeWorkdays::class]);

        RecomputeWorkdays::dispatchFor([
            ['employee_id' => $employee->id, 'time' => '2026-09-01 08:01:23'],
            ['employee_id' => $employee->id, 'time' => '2026-09-04 17:00:00'],
        ]);

        Queue::assertPushedTimes(RecomputeWorkdays::class, 1);
        Queue::assertPushed(RecomputeWorkdays::class, function (RecomputeWorkdays $job) use ($employee): bool {
            return $job->employeeId === $employee->id
                && $job->from === '2026-08-29'
                && $job->to === '2026-09-04';
        });
    }

    /**
     * Four days apart abuts; five does not. The number is the three-day
     * backreach plus one: [D − 3, D] and [D + 1, D + 4] are adjacent
     * and disjoint, and without merging them they dispatch as two jobs.
     * WithoutOverlapping then serialises in queue order, so the later
     * window can run first and workday D+1 claims a timelog the 72:00
     * slot cap still lets D reach — the violation "Across midnight"
     * rule 1 exists to prevent. Drop `addDay()` from the merge and
     * 8 September with 12 September splits. Five days apart
     * ([D − 3, D] and [D + 2, D + 5]) leaves a day of gap, so nothing
     * can be contended and they stay two jobs.
     */
    public function test_punches_four_days_apart_queue_one_job_because_the_windows_abut(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        Queue::fake([RecomputeWorkdays::class]);

        RecomputeWorkdays::dispatchFor([
            ['employee_id' => $employee->id, 'time' => '2026-09-08 08:00:00'],
            ['employee_id' => $employee->id, 'time' => '2026-09-12 08:00:00'],
        ]);

        Queue::assertPushedTimes(RecomputeWorkdays::class, 1);
        Queue::assertPushed(RecomputeWorkdays::class, function (RecomputeWorkdays $job) use ($employee): bool {
            return $job->employeeId === $employee->id
                && $job->from === '2026-09-05'
                && $job->to === '2026-09-12';
        });
    }

    /**
     * Five days is the three-day backreach plus two, so [D − 3, D] and
     * [D + 2, D + 5] leave a gap. See the four-day test for why that
     * gap is the one `addDay()` in the merge exists to refuse to close.
     */
    public function test_punches_five_days_apart_queue_two_jobs_because_the_windows_do_not_abut(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        Queue::fake([RecomputeWorkdays::class]);

        RecomputeWorkdays::dispatchFor([
            ['employee_id' => $employee->id, 'time' => '2026-09-08 08:00:00'],
            ['employee_id' => $employee->id, 'time' => '2026-09-13 08:00:00'],
        ]);

        Queue::assertPushedTimes(RecomputeWorkdays::class, 2);
        Queue::assertPushed(RecomputeWorkdays::class, function (RecomputeWorkdays $job) use ($employee): bool {
            return $job->employeeId === $employee->id
                && $job->from === '2026-09-05'
                && $job->to === '2026-09-08';
        });
        Queue::assertPushed(RecomputeWorkdays::class, function (RecomputeWorkdays $job) use ($employee): bool {
            return $job->employeeId === $employee->id
                && $job->from === '2026-09-10'
                && $job->to === '2026-09-13';
        });
    }

    public function test_a_stray_device_clock_queues_its_own_job_and_does_not_span_the_gap(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        Queue::fake([RecomputeWorkdays::class]);

        RecomputeWorkdays::dispatchFor([
            ['employee_id' => $employee->id, 'time' => '2026-09-01 08:01:23'],
            ['employee_id' => $employee->id, 'time' => '2000-01-01 08:00:00'],
        ]);

        Queue::assertPushedTimes(RecomputeWorkdays::class, 2);
        Queue::assertPushed(RecomputeWorkdays::class, function (RecomputeWorkdays $job) use ($employee): bool {
            return $job->employeeId === $employee->id
                && $job->from === '1999-12-29'
                && $job->to === '2000-01-01';
        });
        Queue::assertPushed(RecomputeWorkdays::class, function (RecomputeWorkdays $job) use ($employee): bool {
            return $job->employeeId === $employee->id
                && $job->from === '2026-08-29'
                && $job->to === '2026-09-01';
        });
    }

    public function test_an_unresolved_pair_queues_nothing(): void
    {
        Queue::fake([RecomputeWorkdays::class]);

        RecomputeWorkdays::dispatchFor([[
            'employee_id' => null,
            'time' => '2026-09-01 08:01:23',
        ]]);

        Queue::assertNotPushed(RecomputeWorkdays::class);
    }

    public function test_the_span_extends_one_day_when_the_next_day_is_a_regular_holiday(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Holiday::factory()->create([
            'agency_id' => $agency->id,
            'date' => '2026-09-02',
            'type' => HolidayType::Regular,
        ]);

        app(Tenant::class)->forget();

        (new RecomputeWorkdays($employee->id, '2026-08-29', '2026-09-01'))
            ->handle(app(Tenant::class));

        $this->assertTrue(
            Workday::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', '2026-09-02')
                ->exists(),
        );
    }

    public function test_a_national_regular_holiday_extends_the_span(): void
    {
        Holiday::factory()->national()->create([
            'date' => '2026-09-02',
            'type' => HolidayType::Regular,
            'name' => 'National Day',
        ]);
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        app(Tenant::class)->forget();

        (new RecomputeWorkdays($employee->id, '2026-08-29', '2026-09-01'))
            ->handle(app(Tenant::class));

        $this->assertTrue(
            Workday::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', '2026-09-02')
                ->exists(),
        );
    }

    /** @return array<string, array{0: HolidayType}> */
    public static function nonRegularHolidayTypes(): array
    {
        return [
            'special' => [HolidayType::Special],
            'working' => [HolidayType::Working],
            'local' => [HolidayType::Local],
        ];
    }

    #[DataProvider('nonRegularHolidayTypes')]
    public function test_the_span_does_not_extend_for_a_non_regular_holiday(HolidayType $type): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Holiday::factory()->create([
            'agency_id' => $agency->id,
            'date' => '2026-09-02',
            'type' => $type,
        ]);

        app(Tenant::class)->forget();

        (new RecomputeWorkdays($employee->id, '2026-08-29', '2026-09-01'))
            ->handle(app(Tenant::class));

        $this->assertFalse(
            Workday::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', '2026-09-02')
                ->exists(),
        );
    }

    public function test_a_locked_ledger_does_not_fail_the_job(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Ledger::factory()->locked()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'month' => '2026-09-01',
        ]);

        app(Tenant::class)->forget();

        (new RecomputeWorkdays($employee->id, '2026-09-08', '2026-09-08'))
            ->handle(app(Tenant::class));

        $this->assertFalse(Workday::query()->where('employee_id', $employee->id)->exists());
    }
}
