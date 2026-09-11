<?php

namespace Tests\Feature\Attendance;

use App\Attendance\Almanac;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Holiday;
use App\Models\Suspension;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What holidays, work suspensions and exemptions reach one employee on
 * each date (05-calendar.md). A loader: it answers *what applies*, never
 * *what that does to the day*.
 */
class AlmanacTest extends TestCase
{
    private Agency $agency;

    private CarbonImmutable $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->date = CarbonImmutable::parse('2026-09-15');
        $this->agency = Agency::factory()->create();
    }

    /**
     * National holidays must be written before the tenant is set:
     * BelongsToAgency refuses an explicit agency_id that disagrees with it,
     * and a national row belongs to the platform agency.
     */
    private function enter(): void
    {
        $this->withTenant($this->agency);
    }

    private function employee(): Employee
    {
        $this->enter();

        return Employee::factory()->create(['agency_id' => $this->agency->id]);
    }

    /** An employee whose substantive placement covers 2026. */
    private function deployedIn(Workgroup $workgroup): Employee
    {
        $this->enter();

        $employee = Employee::factory()->create(['agency_id' => $this->agency->id]);
        Deployment::factory()->create([
            'agency_id' => $this->agency->id,
            'workgroup_id' => $workgroup->id,
            'employee_id' => $employee->id,
            'starts' => '2026-01-01',
            'ends' => '2026-12-31',
        ]);

        return $employee;
    }

    /** @param  Collection<int, mixed>  $rows */
    private function ids(Collection $rows): array
    {
        return $rows->pluck('id')->sort()->values()->all();
    }

    /**
     * A national holiday is owned by the platform agency and applies to
     * every tenant through AgencyOrPlatformScope. Adding an agency_id
     * filter of our own would silently drop it.
     */
    public function test_a_national_holiday_reaches_a_tenant_employee(): void
    {
        $holiday = Holiday::factory()->national()->create([
            'date' => '2026-11-30',
            'name' => 'Bonifacio Day',
        ]);
        $employee = $this->employee();
        $date = CarbonImmutable::parse('2026-11-30');

        $almanac = Almanac::for($employee, $date, $date);

        $this->assertSame([$holiday->id], $this->ids($almanac->holidays($date)));
    }

    /**
     * Two holidays on one date is the normal case this milestone exists
     * to preserve: a local charter day on a national special day, both
     * owed. Deciding which governs is the next chunk's job.
     */
    public function test_two_holidays_on_one_date_are_both_returned(): void
    {
        $national = Holiday::factory()->national()->create([
            'date' => '2026-11-30',
            'name' => 'Bonifacio Day',
        ]);
        $employee = $this->employee();
        $local = Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-11-30',
            'name' => 'Charter Day',
        ]);
        $date = CarbonImmutable::parse('2026-11-30');

        $almanac = Almanac::for($employee, $date, $date);

        $this->assertSame(
            collect([$national->id, $local->id])->sort()->values()->all(),
            $this->ids($almanac->holidays($date)),
        );
    }

    /** Agency-wide (`workgroup_id` null): everyone deployed on the date. */
    public function test_an_agency_wide_suspension_reaches_a_deployed_employee(): void
    {
        $workgroup = Workgroup::factory()->create(['agency_id' => $this->agency->id]);
        $employee = $this->deployedIn($workgroup);
        $suspension = Suspension::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => $this->date,
        ]);

        $almanac = Almanac::for($employee, $this->date, $this->date);

        $this->assertSame([$suspension->id], $this->ids($almanac->suspensions($this->date)));
    }

    /**
     * A workgroup suspension reaches an employee through their substantive
     * placement — no movement covering the date, placement in the subtree.
     */
    public function test_a_workgroup_suspension_reaches_an_employee_through_their_placement(): void
    {
        $mother = Workgroup::factory()->create(['agency_id' => $this->agency->id]);
        $employee = $this->deployedIn($mother);
        $suspension = Suspension::factory()->forWorkgroup($mother)->create(['date' => $this->date]);

        $almanac = Almanac::for($employee, $this->date, $this->date);

        $this->assertSame([$suspension->id], $this->ids($almanac->suspensions($this->date)));
    }

    /**
     * The clause rule 3 exists to keep: the same mother-office closure must
     * not reach someone detailed *out* of it. Their operative deployment
     * that day is the receiving office; dropping the negative "no movement"
     * clause would excuse a day they actually worked elsewhere.
     */
    public function test_a_workgroup_suspension_does_not_reach_an_employee_detailed_out(): void
    {
        $mother = Workgroup::factory()->create(['agency_id' => $this->agency->id]);
        $receiving = Workgroup::factory()->create(['agency_id' => $this->agency->id]);
        $employee = $this->deployedIn($mother);
        $placement = $employee->deployments()->whereNull('parent_id')->first();
        Deployment::factory()->under($placement)->create([
            'workgroup_id' => $receiving->id,
            'starts' => '2026-09-01',
            'ends' => '2026-09-30',
        ]);
        Suspension::factory()->forWorkgroup($mother)->create(['date' => $this->date]);

        $almanac = Almanac::for($employee, $this->date, $this->date);

        $this->assertSame([], $this->ids($almanac->suspensions($this->date)));
    }

    /**
     * An exemption is a closed date range, not a date. RA 11210's 105 days
     * is one row that must appear under every day it covers, including
     * Saturdays — and still appear when the Almanac range is a slice of
     * the leave, which is the case a `whereBetween('date', …)` would miss.
     */
    public function test_a_multi_day_exemption_appears_under_every_date_it_covers(): void
    {
        $employee = $this->employee();
        $first = CarbonImmutable::parse('2026-09-01');
        $exemption = Exemption::factory()->spanning(105)->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'date' => $first,
        ]);

        $almanac = Almanac::for($employee, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-03'));

        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $day) {
            $this->assertSame(
                [$exemption->id],
                $this->ids($almanac->exemptions(CarbonImmutable::parse($day))),
                $day,
            );
        }

        $this->assertSame([], $this->ids($almanac->exemptions($first->subDay())));
        $this->assertSame([], $this->ids($almanac->exemptions($first->addDays(105))));
    }

    /**
     * `personal` excuses no minute (decision 19) but is still recorded and
     * printed. Dropping it here would lose it from the form; whether it
     * excuses anything is the deriver's question, asked through excused().
     */
    public function test_a_personal_slip_is_still_returned(): void
    {
        $employee = $this->employee();
        $slip = Exemption::factory()->personal()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'date' => $this->date,
        ]);

        $almanac = Almanac::for($employee, $this->date, $this->date);

        $this->assertSame([$slip->id], $this->ids($almanac->exemptions($this->date)));
    }

    /**
     * Three loads for the range, plus at most one appliesTo() exists() per
     * suspension found. Thirty days and one day, covering the same rows,
     * must cost the same. The readers themselves must not query.
     */
    public function test_loading_thirty_days_issues_the_same_queries_as_one(): void
    {
        $workgroup = Workgroup::factory()->create(['agency_id' => $this->agency->id]);
        $employee = $this->deployedIn($workgroup);
        Holiday::factory()->create(['agency_id' => $this->agency->id, 'date' => $this->date]);
        Suspension::factory()->create(['agency_id' => $this->agency->id, 'date' => $this->date]);
        Exemption::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'date' => $this->date,
        ]);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $almanac = Almanac::for($employee, $this->date, $this->date);
        $one = $queries;

        $queries = 0;
        Almanac::for($employee, $this->date->subDays(14), $this->date->addDays(15));
        $thirty = $queries;

        $this->assertSame($one, $thirty);

        $queries = 0;
        $almanac->holidays($this->date);
        $almanac->suspensions($this->date);
        $almanac->exemptions($this->date);

        $this->assertSame(0, $queries, 'readers filter the already-loaded set in PHP');
    }

    /** from after to loads nothing; every reader is empty. */
    public function test_from_after_to_loads_nothing(): void
    {
        $employee = $this->employee();
        Holiday::factory()->create(['agency_id' => $this->agency->id, 'date' => $this->date]);

        $almanac = Almanac::for($employee, $this->date->addDay(), $this->date);

        $this->assertTrue($almanac->holidays($this->date)->isEmpty());
        $this->assertTrue($almanac->suspensions($this->date)->isEmpty());
        $this->assertTrue($almanac->exemptions($this->date)->isEmpty());
    }
}
