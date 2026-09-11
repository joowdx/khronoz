<?php

namespace Database\Seeders;

use App\Enums\HolidayType;
use App\Enums\Preset;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Suspension;
use App\Models\Team;
use App\Models\Turn;
use App\Models\User;
use App\Models\Workgroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The hospital of 04-scheduling.md's fourth worked example: three eight-hour
 * shifts on one 21-day cycle, three teams anchored a week apart, twelve
 * nurses following them.
 *
 * **Not called by `DatabaseSeeder`.** Run it deliberately:
 *
 *     php artisan db:seed --class=RotationSeeder
 *
 * **It seeds the plan and nothing the plan produced** — no timelogs, no
 * punches, no workdays, no ledgers, no syncs. That is the whole reason it
 * exists next to `AttendanceSeeder`, which seeds facts and runs the engine
 * over them and takes minutes. The roster grid *projects*: it reads the
 * rosters, walks `Cycle::position()` over the dates on screen and draws the
 * turn it lands on, so a quarter of attendance history would be forty
 * thousand rows the screen never reads and a minutes-long wait every time
 * somebody resets the database while building it. This one is about forty
 * rows and runs in under a second.
 *
 * **The staircase is the point.** The three teams are one schedule and three
 * anchors seven days apart, so on any date they sit seven positions apart —
 * whoever is on their third Morning has one team on their third Afternoon and
 * one on their third Night, and a week later each has moved down a block. A
 * grid of one team is a stripe; a grid of three is the rotation.
 *
 * **Everything is written under one transaction**, for two reasons. The
 * schedule and its full run of turns *must* share one: `turns_complete` is
 * DEFERRABLE INITIALLY DEFERRED, so it is checked at COMMIT, and outside an
 * explicit transaction the bare `schedules` insert commits on its own as a
 * complete unit of work with no turns in it and raises P0001. The rest is in
 * there because the guard below keys on the agency code, and a run that died
 * halfway would leave a half-built hospital the guard then skips forever.
 *
 * No `Tenant` is set: nothing here reads one (that is `ImportTimelogs` and
 * `Computer`, and neither runs), so this follows `DatabaseSeeder`'s shape
 * instead and sets `agency_id` explicitly on every row.
 */
class RotationSeeder extends Seeder
{
    /** The agency this builds, and the code to sign in under. */
    private const CODE = 'CDH';

    /** Cycle day zero per team — seven days apart, as the worked example has them. */
    private const TEAMS = [
        'Team A' => '2026-09-07',
        'Team B' => '2026-09-14',
        'Team C' => '2026-09-21',
    ];

    /** Nurses per team. */
    private const PER_TEAM = 4;

    /** When every roster and placement opens: well before the September the grid lands on. */
    private const STARTS = '2026-01-01';

    /** One of each per team, so a row of the grid reads like a ward and not like clones. */
    private const RANKS = ['Nurse III', 'Nurse II', 'Nurse I', 'Nursing Attendant II'];

    public function run(): void
    {
        $this->call(PlatformSeeder::class);

        if (Agency::where('code', self::CODE)->exists()) {
            $this->command?->getOutput()->writeln('  <comment>'.self::CODE.' already seeded, skipping.</comment>');

            return;
        }

        DB::transaction(function (): void {
            $agency = Agency::factory()->create([
                'code' => self::CODE,
                'name' => 'City District Hospital',
            ]);

            // Timekeeper rather than the bare user `AttendanceSeeder` makes:
            // this fixture exists to be *looked at*, and a sign-in that lands
            // on a screen the policies refuse is a fixture nobody can use.
            $clerk = User::factory()->forAgency($agency)->preset(Preset::Timekeeper)->create([
                'name' => 'Nursing Office Clerk',
                'email' => strtolower(self::CODE).'.hr@khronoz.test',
            ]);

            $ward = Workgroup::factory()->create([
                'agency_id' => $agency->id,
                'kind' => 'department',
                'code' => 'NURS',
                'name' => 'Nursing Service',
            ]);

            $schedule = $this->rotation($agency, $this->shifts($agency));

            $this->nurses($agency, $ward, $this->teams($agency, $schedule));
            $this->unrostered($agency, $ward);
            $this->calendar($agency, $ward, $clerk);
        });

        $this->command?->getOutput()->writeln(
            '  <info>'.self::CODE.'</info>: three teams on a 21-day rotation, '
            .(count(self::TEAMS) * self::PER_TEAM).' nurses rostered and 2 without. '
            .'Sign in as <info>'.strtolower(self::CODE).'.hr@khronoz.test</info> / password.'
        );
    }

    /**
     * The five shifts: the three that rotate, plus the two that have no slots.
     *
     * `window` is the worked example's `[-120, 120]` on every slot — two hours
     * either side, which is what keeps a 22:00 tap off the Afternoon shift
     * that ended at 22:00. `required` is 480 on all three including Night:
     * the number is what a complete day credits and is not derived from the
     * slots, and `30:00` is 06:00 the next day, so Night is eight hours like
     * the others and not thirty.
     *
     * `color` is stored, never derived (rule 8), and runs 1, 2, 3 across the
     * rotation so the grid's three blocks are three colours. `Off` and
     * `Remote` take the next indices for the same reason a new shift does —
     * the lowest the agency is not using — though theirs is never read, since
     * both are drawn from the empty `slots` and the `remote` flag.
     *
     * `Remote` is on no turn, deliberately: the worked example's 21 turns are
     * Morning, Afternoon, Night and Off, and adding a Remote day would be a
     * different schedule. It exists as a row so the shift list and the grid's
     * legend have a remote shift to draw.
     *
     * @return array{morning: Shift, afternoon: Shift, night: Shift, remote: Shift, off: Shift}
     */
    private function shifts(Agency $agency): array
    {
        $rotating = fn (string $name, string $in, string $out, int $color): Shift => Shift::factory()->create([
            'agency_id' => $agency->id,
            'name' => $name,
            'slots' => [['in' => $in, 'out' => $out, 'window' => [-120, 120]]],
            'required' => 480,
            'color' => $color,
        ]);

        return [
            'morning' => $rotating('Morning', '06:00', '14:00', 1),
            'afternoon' => $rotating('Afternoon', '14:00', '22:00', 2),
            'night' => $rotating('Night', '22:00', '30:00', 3),
            // Credited on attestation, so `required` may stand (Flexiplace,
            // OP MC 114); `remote` true is what tells it from `Off`, since
            // both have empty slots.
            'remote' => Shift::factory()->remote()->create([
                'agency_id' => $agency->id,
                'name' => 'Remote',
                'required' => 480,
                'color' => 4,
            ]),
            'off' => Shift::factory()->off()->create([
                'agency_id' => $agency->id,
                'name' => 'Off',
                'color' => 5,
            ]),
        ];
    }

    /**
     * `Rotation`, length 21: Morning ×5, Off ×2, Afternoon ×5, Off ×2,
     * Night ×5, Off ×2 — three seven-day blocks, which is what lets three
     * anchors a week apart cover all three shifts.
     *
     * Written out rather than reached through `ScheduleFactory::withTurns()`,
     * which hard-codes five working days and two off against one shift: true
     * of a standard week and not of this.
     *
     * **Must run inside the seeder's transaction** — see the class docblock
     * for `turns_complete`. The schedule's `length` is `count($turns)` rather
     * than a literal 21 so the two can never disagree; disagreeing is exactly
     * what that constraint raises on.
     *
     * @param  array{morning: Shift, afternoon: Shift, night: Shift, remote: Shift, off: Shift}  $shifts
     */
    private function rotation(Agency $agency, array $shifts): Schedule
    {
        $turns = [
            ...array_fill(0, 5, $shifts['morning']),
            ...array_fill(0, 2, $shifts['off']),
            ...array_fill(0, 5, $shifts['afternoon']),
            ...array_fill(0, 2, $shifts['off']),
            ...array_fill(0, 5, $shifts['night']),
            ...array_fill(0, 2, $shifts['off']),
        ];

        $schedule = Schedule::factory()->create([
            'agency_id' => $agency->id,
            'name' => 'Rotation',
            'length' => count($turns),
        ]);

        foreach ($turns as $position => $shift) {
            Turn::factory()->create([
                'agency_id' => $agency->id,
                'schedule_id' => $schedule->id,
                'shift_id' => $shift->id,
                'position' => $position,
            ]);
        }

        return $schedule;
    }

    /**
     * The three cohorts: one schedule, three anchors.
     *
     * A team has no members of its own — the rosters carrying its `team_id`
     * *are* its membership — so this writes three rows and `nurses()` below
     * writes the membership.
     *
     * @return list<Team>
     */
    private function teams(Agency $agency, Schedule $schedule): array
    {
        $teams = [];

        foreach (self::TEAMS as $name => $anchor) {
            $teams[] = Team::factory()->on($schedule, $anchor)->create([
                'agency_id' => $agency->id,
                'name' => $name,
            ]);
        }

        return $teams;
    }

    /**
     * Four nurses per team, each placed in the ward and rostered onto their
     * team's cycle.
     *
     * The roster *copies* the team's schedule and anchor and points back with
     * `team_id`, which is what `fromTeam()` does and what makes the two able
     * to diverge later without taking anyone off the cohort. `starts` is nine
     * months before the anchor and `ends` is null: an anchor is cycle day
     * zero and not a start date, and `Cycle::position()` wraps a date before
     * it like any other.
     *
     * `AssignTeam` is the action that does this in the application, and this
     * deliberately does not call it: it dispatches `FanOutRecompute` per
     * employee, which is a queue this fixture has no work for and, under a
     * `sync` connection, the workdays this seeder exists not to write.
     *
     * @param  list<Team>  $teams
     */
    private function nurses(Agency $agency, Workgroup $ward, array $teams): void
    {
        foreach ($teams as $team) {
            for ($index = 0; $index < self::PER_TEAM; $index++) {
                $nurse = Employee::factory()->create([
                    'agency_id' => $agency->id,
                    'position' => self::RANKS[$index % count(self::RANKS)],
                ]);

                Deployment::factory()->create([
                    'agency_id' => $agency->id,
                    'employee_id' => $nurse->id,
                    'workgroup_id' => $ward->id,
                    'starts' => self::STARTS,
                    'ends' => null,
                ]);

                Roster::factory()->fromTeam($team)->create([
                    'agency_id' => $agency->id,
                    'employee_id' => $nurse->id,
                    'starts' => self::STARTS,
                    'ends' => null,
                ]);
            }
        }
    }

    /**
     * Two people in the ward with no roster at all, which the grid lists on
     * its own and an agency of twelve tidy rotations never produces: a nurse
     * hired this month whose assignment has not been issued yet, and a clerk
     * who will never be on the rotation.
     */
    private function unrostered(Agency $agency, Workgroup $ward): void
    {
        $people = [
            ['Nurse I', '2026-09-01'],
            ['Administrative Aide IV', self::STARTS],
        ];

        foreach ($people as [$position, $starts]) {
            $employee = Employee::factory()->create([
                'agency_id' => $agency->id,
                'position' => $position,
            ]);

            Deployment::factory()->create([
                'agency_id' => $agency->id,
                'employee_id' => $employee->id,
                'workgroup_id' => $ward->id,
                'starts' => $starts,
                'ends' => null,
            ]);
        }
    }

    /**
     * Two holidays and two suspensions inside the September the grid opens
     * on, so its wash and its legend have something to say.
     *
     * Owned by the agency rather than the platform row: a national holiday
     * would land on every other seeded agency's grid too, and these exist for
     * this one. The pair differ in kind — a local one declared by ordinance
     * and a special day by proclamation — and so do the suspensions: one
     * whole day agency-wide, the shape a typhoon signal takes, and one
     * afternoon in the ward alone. The partial is a *pair* of times, since
     * `suspensions_hours_paired` refuses "suspended from one o'clock until
     * nothing".
     */
    private function calendar(Agency $agency, Workgroup $ward, User $clerk): void
    {
        Holiday::factory()->create([
            'agency_id' => $agency->id,
            'date' => '2026-09-08',
            'name' => 'Hospital Charter Day',
            'type' => HolidayType::Local,
            'reference' => 'City Ordinance No. 512',
        ]);

        Holiday::factory()->create([
            'agency_id' => $agency->id,
            'date' => '2026-09-21',
            'name' => 'Feast of the Patroness',
            'type' => HolidayType::Special,
            'reference' => 'Proclamation No. 1236',
        ]);

        Suspension::factory()->create([
            'agency_id' => $agency->id,
            'workgroup_id' => null,
            'date' => '2026-09-15',
            'reason' => 'Typhoon Signal No. 2',
            'user_id' => $clerk->id,
        ]);

        Suspension::factory()->forWorkgroup($ward)->partial('13:00:00', '17:00:00')->create([
            'agency_id' => $agency->id,
            'date' => '2026-09-24',
            'reason' => 'Water interruption',
            'user_id' => $clerk->id,
        ]);
    }
}
