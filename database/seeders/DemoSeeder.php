<?php

namespace Database\Seeders;

use App\Actions\LockLedger;
use App\Enums\ExemptionType;
use App\Enums\HolidayType;
use App\Enums\OvertimeMode;
use App\Enums\Preset;
use App\Enums\Work;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Exemption;
use App\Models\Holiday;
use App\Models\Overtime;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Suspension;
use App\Models\Team;
use App\Models\Terminal;
use App\Models\Turn;
use App\Models\User;
use App\Models\Workgroup;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The showcase agency: a small city government whose offices each keep hours
 * for a different reason, so that every shape the scheduling domain supports
 * appears somewhere a demo can be pointed at.
 *
 * Explicit only — `db:seed` never reaches it. Running it destroys and rebuilds
 * the demo agency, which is why it is not wired into `DatabaseSeeder`.
 */
class DemoSeeder extends Seeder
{
    use SeedsAttendance;

    private const CODE = 'demo';

    /** How many of an office's people carry each habit, as parts of a whole. */
    private const HABITS = [
        'punctual' => 8,
        'late' => 4,
        'undertime' => 2,
        'overtime' => 3,
        'absentee' => 2,
        'forgetful' => 1,
    ];

    /**
     * Deleting an agency means walking the graph inwards, because every paired
     * key is ON DELETE RESTRICT. Children first, parents last.
     */
    private const PURGE = [
        'acceptances', 'attestations', 'renditions', 'locations', 'documents',
        'punches', 'workdays', 'ledgers', 'timelogs', 'enrollments', 'syncs',
        'terminals', 'overtimes', 'exemptions', 'suspensions', 'holidays',
        'rosters', 'teams', 'turns', 'schedules', 'shifts', 'deployments',
        'policies', 'employees', 'workgroups', 'cadences', 'passkeys',
        'identities', 'users', 'agencies',
    ];

    public function run(): void
    {
        $this->quieten();

        $this->call(PlatformSeeder::class);
        $this->call(DefaultsSeeder::class);

        $today = CarbonImmutable::today();
        $from = $today->subMonths(3)->startOfMonth();
        $anchor = $from->startOfWeek(CarbonInterface::MONDAY);

        $this->purge();

        $agency = Agency::factory()->create([
            'code' => self::CODE,
            'name' => 'Demo City Government',
        ]);

        app(Tenant::class)->set($agency);

        try {
            $clerk = $this->users($agency);
            $workgroups = $this->workgroups($agency);
            $terminals = $this->terminals($agency, $workgroups);
            $schedules = $this->schedules($agency, $this->shifts($agency));
            $teams = $this->teams($agency, $schedules, $anchor);

            $people = $this->people($agency, $workgroups, $terminals, $schedules, $teams, $anchor, $from);

            $this->heads($workgroups);
            $this->calendar($agency, $workgroups, $clerk, $people, $from, $today);
            $this->attendance($agency, $people, $from, $today);
            $this->compute($agency, $people, $from, $today);
            $this->close($agency, $clerk, $people, $from);
        } finally {
            app(Tenant::class)->forget();
        }

        $this->report($from, $today);
    }

    /**
     * The application role has DELETE revoked on timelogs, syncs, attestations,
     * acceptances, documents, locations and renditions — they are integrity
     * boundaries (AppRoleGrants::restrict()). Only the owner can undo a demo.
     */
    private function purge(): void
    {
        $agency = Agency::withoutGlobalScopes()->where('code', self::CODE)->first();

        if ($agency === null) {
            return;
        }

        if ($agency->platform) {
            throw new RuntimeException('Refusing to purge: the '.self::CODE.' row is the platform agency.');
        }

        if (config('database.connections.owner.username') === null) {
            throw new RuntimeException(
                'Purging the demo agency needs the owner connection, which has no fallback credentials. '
                .'Set DB_OWNER_USERNAME and DB_OWNER_PASSWORD for this command.'
            );
        }

        $owner = DB::connection('owner');

        $owner->transaction(function () use ($owner, $agency): void {
            // Locked ledgers, attested months and frozen periods are each defended
            // by a row trigger that exists to refuse exactly this. `USER` leaves the
            // foreign keys enforcing, so the order below is still checked, and the
            // whole purge rolls back together if any of it is wrong.
            foreach (self::PURGE as $table) {
                $owner->statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
            }

            // Self- and back-references first: nothing below can delete a row
            // another row in the same table, or in an earlier table, still points at.
            $owner->table('users')->where('agency_id', $agency->id)->update(['employee_id' => null]);
            $owner->table('workgroups')->where('agency_id', $agency->id)->update(['parent_id' => null, 'head_id' => null]);
            $owner->table('deployments')->where('agency_id', $agency->id)->update(['parent_id' => null]);
            $owner->table('schedules')->where('agency_id', $agency->id)->update(['fallback_shift_id' => null]);
            $owner->table('employees')->where('agency_id', $agency->id)->update(['cadence_id' => null]);

            foreach (self::PURGE as $table) {
                $column = $table === 'agencies' ? 'id' : 'agency_id';

                $owner->table($table)->where($column, $agency->id)->delete();
            }

            foreach (self::PURGE as $table) {
                $owner->statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
        });

        $this->command?->getOutput()->writeln('  <comment>'.self::CODE.'</comment>: purged, rebuilding…');
    }

    private function users(Agency $agency): User
    {
        $clerk = User::factory()->forAgency($agency)->preset(Preset::Timekeeper)->create([
            'name' => 'Human Resource Officer',
            'email' => 'demo.hr@khronoz.test',
        ]);

        User::factory()->forAgency($agency)->preset(Preset::Admin)->create([
            'name' => 'Agency Administrator',
            'email' => 'demo.admin@khronoz.test',
        ]);

        User::factory()->forAgency($agency)->preset(Preset::Viewer)->create([
            'name' => 'Audit Viewer',
            'email' => 'demo.viewer@khronoz.test',
        ]);

        return $clerk;
    }

    /**
     * `kind` is a free string on purpose — the vocabulary belongs to the
     * agency, so `section` and `unit` are as legitimate as `department`.
     *
     * @return array<string, Workgroup>
     */
    private function workgroups(Agency $agency): array
    {
        $tree = [
            ['OED', 'Office of the City Mayor', 'office', null],
            ['HOSP', 'City District Hospital', 'department', 'OED'],
            ['ER', 'Emergency Room', 'section', 'HOSP'],
            ['WARD', 'Inpatient Ward', 'section', 'HOSP'],
            ['DRRM', 'Disaster Risk Reduction and Management Office', 'department', 'OED'],
            ['OPCEN', 'Emergency Operations Center', 'section', 'DRRM'],
            ['RESCUE', 'Search and Rescue Unit', 'unit', 'DRRM'],
            ['ICT', 'Information and Communications Technology Office', 'department', 'OED'],
            ['DEV', 'Applications Development', 'section', 'ICT'],
            ['CYBER', 'Cyber Security', 'section', 'ICT'],
            ['NOC', 'Network Operations', 'section', 'ICT'],
            ['HRMO', 'Human Resource Management Office', 'department', 'OED'],
            ['ACCTG', 'Accounting Office', 'department', 'OED'],
            ['ENGR', 'Engineering Office', 'department', 'OED'],
            ['GSO', 'General Services Office', 'department', 'OED'],
        ];

        $workgroups = [];

        foreach ($tree as [$code, $name, $kind, $parent]) {
            $attributes = ['agency_id' => $agency->id, 'kind' => $kind, 'code' => $code, 'name' => $name];

            $workgroups[$code] = $parent === null
                ? Workgroup::factory()->create($attributes)
                : Workgroup::factory()->under($workgroups[$parent])->create($attributes);
        }

        return $workgroups;
    }

    /**
     * @param  array<string, Workgroup>  $workgroups
     * @return array<string, Terminal>
     */
    private function terminals(Agency $agency, array $workgroups): array
    {
        return [
            'hall' => Terminal::factory()->create([
                'agency_id' => $agency->id,
                'code' => 'DEMO-01',
                'name' => 'City Hall Main Lobby',
            ]),
            'hospital' => Terminal::factory()->forWorkgroup($workgroups['HOSP'])->create([
                'code' => 'DEMO-02',
                'name' => 'Hospital Staff Entrance',
            ]),
            'opcen' => Terminal::factory()->forWorkgroup($workgroups['DRRM'])->create([
                'code' => 'DEMO-03',
                'name' => 'Operations Center Gate',
            ]),
        ];
    }

    /**
     * `required` is credited policy, not a duration read off the slots, and an
     * `out` past 24:00 is the following morning — which is how a tour crosses
     * midnight. Those set `trust`, so the device's own in/out byte picks the
     * slot side rather than proximity.
     *
     * @return array<string, Shift>
     */
    private function shifts(Agency $agency): array
    {
        $shifts = [
            'standard' => ['name' => 'Standard 8-5', 'slots' => [
                ['in' => '08:00', 'out' => '12:00', 'window' => [-240, 180]],
                ['in' => '13:00', 'out' => '17:00', 'window' => [-120, 300]],
            ], 'required' => 480, 'color' => 6],

            'flexi' => ['name' => 'Flexitime 7-4', 'slots' => [
                ['in' => '07:00', 'out' => '11:00', 'window' => [-60, 240]],
                ['in' => '12:00', 'out' => '16:00', 'window' => [-60, 300]],
            ], 'required' => 480, 'flex' => 180, 'color' => 6],

            'compressed' => ['name' => 'Compressed 7-6', 'slots' => [
                ['in' => '07:00', 'out' => '12:00', 'window' => [-120, 240]],
                ['in' => '13:00', 'out' => '18:00', 'window' => [-120, 300]],
            ], 'required' => 600, 'color' => 3],

            'split' => ['name' => 'Split 6-10 and 2-6', 'slots' => [
                ['in' => '06:00', 'out' => '10:00', 'window' => [-60, 120]],
                ['in' => '14:00', 'out' => '18:00', 'window' => [-60, 180]],
            ], 'required' => 480, 'color' => 2],

            'morning' => ['name' => 'Ward Morning', 'slots' => [
                ['in' => '06:00', 'out' => '14:00', 'window' => [-60, 120]],
            ], 'required' => 480, 'trust' => true, 'color' => 1],

            'afternoon' => ['name' => 'Ward Afternoon', 'slots' => [
                ['in' => '14:00', 'out' => '22:00', 'window' => [-60, 120]],
            ], 'required' => 480, 'trust' => true, 'color' => 2],

            'night' => ['name' => 'Ward Night', 'slots' => [
                ['in' => '22:00', 'out' => '30:00', 'window' => [-60, 120]],
            ], 'required' => 480, 'trust' => true, 'color' => 3],

            'day12' => ['name' => 'Emergency Day 12h', 'slots' => [
                ['in' => '07:00', 'out' => '19:00', 'window' => [-60, 120]],
            ], 'required' => 720, 'trust' => true, 'color' => 5],

            'night12' => ['name' => 'Emergency Night 12h', 'slots' => [
                ['in' => '19:00', 'out' => '31:00', 'window' => [-60, 120]],
            ], 'required' => 720, 'trust' => true, 'color' => 7],

            'duty24' => ['name' => 'Duty 24h', 'slots' => [
                ['in' => '08:00', 'out' => '32:00', 'window' => [-60, 120]],
            ], 'required' => 1440, 'trust' => true, 'color' => 8],
        ];

        $created = [];

        foreach ($shifts as $key => $attributes) {
            $created[$key] = Shift::factory()->create($attributes + [
                'agency_id' => $agency->id,
                'flex' => 0,
                'trust' => false,
            ]);
        }

        // Both have empty slots; `remote` is the only thing that tells a day
        // credited on attestation from a rest day.
        $created['remote'] = Shift::factory()->remote()->create([
            'agency_id' => $agency->id,
            'name' => 'Work from Home',
            'required' => 480,
            'color' => 4,
        ]);

        $created['off'] = Shift::factory()->off()->create([
            'agency_id' => $agency->id,
            'name' => 'Rest Day',
            'color' => 1,
        ]);

        return $created;
    }

    /**
     * @param  array<string, Shift>  $shifts
     * @return array<string, array{schedule: Schedule, shifts: list<Shift>}>
     */
    private function schedules(Agency $agency, array $shifts): array
    {
        $s = $shifts['standard'];
        $f = $shifts['flexi'];
        $c = $shifts['compressed'];
        $p = $shifts['split'];
        $r = $shifts['remote'];
        $o = $shifts['off'];

        $cycles = [
            'standard' => ['Standard week', [$s, $s, $s, $s, $s, $o, $o], null],
            'flexi' => ['Flexitime week', [$f, $f, $f, $f, $f, $o, $o], null],
            // A compressed week falls back to an ordinary day when a holiday
            // lands on one of its rest days.
            'compressed' => ['Compressed week, Monday to Thursday', [$c, $c, $c, $c, $o, $o, $o], $s],
            'field' => ['Compressed week, Tuesday to Friday', [$o, $c, $c, $c, $c, $o, $o], $s],
            'cyber' => ['Compressed week with remote Friday', [$c, $c, $c, $c, $r, $o, $o], $s],
            'hybrid' => ['Hybrid week, remote Wednesday and Friday', [$s, $s, $r, $s, $r, $o, $o], null],
            'split' => ['Split-shift six-day week', [$p, $p, $p, $p, $p, $p, $o], null],
            'ward' => ['Ward rotation', [
                ...array_fill(0, 5, $shifts['morning']), $o, $o,
                ...array_fill(0, 5, $shifts['afternoon']), $o, $o,
                ...array_fill(0, 5, $shifts['night']), $o, $o,
            ], null],
            'emergency' => ['Emergency twelve-hour rotation', [
                $shifts['day12'], $shifts['day12'], $shifts['night12'], $shifts['night12'], $o, $o, $o, $o,
            ], null],
            'opcen' => ['Operations 24/48', [$shifts['duty24'], $o, $o], null],
            'rescue' => ['Rescue 24/24', [$shifts['duty24'], $o], null],
        ];

        $schedules = [];

        foreach ($cycles as $key => [$name, $turns, $fallback]) {
            $schedules[$key] = ['schedule' => $this->cycle($agency, $name, $turns, $fallback), 'shifts' => $turns];
        }

        return $schedules;
    }

    /**
     * A trigger requires exactly `length` turns, so the schedule and its turns
     * have to land in one transaction.
     *
     * @param  list<Shift>  $turns
     */
    private function cycle(Agency $agency, string $name, array $turns, ?Shift $fallback): Schedule
    {
        return DB::transaction(function () use ($agency, $name, $turns, $fallback): Schedule {
            $schedule = Schedule::factory()->create([
                'agency_id' => $agency->id,
                'name' => $name,
                'length' => count($turns),
                'fallback_shift_id' => $fallback?->id,
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
        });
    }

    /**
     * Staggering each cohort by an equal slice of the cycle is what makes a
     * rotation cover every day — and it has to be computed from the seeding
     * date, or the grid is only ever correct for one week of one year.
     *
     * @param  array<string, array{schedule: Schedule, shifts: list<Shift>}>  $schedules
     * @return array<string, list<Team>>
     */
    private function teams(Agency $agency, array $schedules, CarbonImmutable $anchor): array
    {
        $cohorts = [
            'ward' => ['Ward Team A', 'Ward Team B', 'Ward Team C'],
            'emergency' => ['Emergency Team 1', 'Emergency Team 2', 'Emergency Team 3', 'Emergency Team 4'],
            'opcen' => ['Operations Alpha', 'Operations Bravo', 'Operations Charlie'],
            'rescue' => ['Rescue Red', 'Rescue Blue'],
        ];

        $teams = [];

        foreach ($cohorts as $key => $names) {
            $schedule = $schedules[$key]['schedule'];
            $stagger = intdiv($schedule->length, count($names));

            foreach ($names as $index => $name) {
                $teams[$key][] = Team::factory()
                    ->on($schedule, $anchor->addDays($index * $stagger)->toDateString())
                    ->create(['agency_id' => $agency->id, 'name' => $name]);
            }
        }

        return $teams;
    }

    /**
     * @return list<array{0: string, 1: int, 2: string, 3: ?string, 4: string, 5: list<string>}>
     */
    private function staffing(): array
    {
        return [
            ['ER', 8, 'emergency', 'emergency', 'hospital', ['Nurse II', 'Nurse I', 'Emergency Medical Technician', 'Nursing Attendant II']],
            ['WARD', 9, 'ward', 'ward', 'hospital', ['Nurse III', 'Nurse II', 'Nurse I', 'Nursing Attendant II']],
            ['HOSP', 1, 'standard', null, 'hospital', ['Medical Officer IV']],
            ['OPCEN', 6, 'opcen', 'opcen', 'opcen', ['Emergency Dispatcher II', 'Emergency Dispatcher I']],
            ['RESCUE', 8, 'rescue', 'rescue', 'opcen', ['Rescue Team Leader', 'Rescuer II', 'Rescuer I']],
            ['DRRM', 1, 'standard', null, 'opcen', ['Government Department Head I']],
            ['DEV', 6, 'hybrid', null, 'hall', ['Information Systems Analyst III', 'Computer Programmer II', 'Computer Programmer I']],
            ['CYBER', 5, 'cyber', null, 'hall', ['Information Security Officer II', 'Information Security Officer I']],
            ['NOC', 3, 'flexi', null, 'hall', ['Network Administrator II', 'Network Administrator I']],
            ['ICT', 1, 'standard', null, 'hall', ['Information Technology Officer III']],
            ['HRMO', 6, 'standard', null, 'hall', ['Human Resource Management Officer III', 'Administrative Officer II', 'Administrative Aide VI']],
            ['ACCTG', 8, 'compressed', null, 'hall', ['Accountant III', 'Accountant II', 'Accounting Clerk III', 'Administrative Aide VI']],
            ['ENGR', 5, 'field', null, 'hall', ['Engineer III', 'Engineer II', 'Construction and Maintenance Foreman']],
            ['GSO', 4, 'split', null, 'hall', ['Administrative Aide VI', 'Administrative Aide IV', 'Utility Worker II']],
            ['OED', 2, 'standard', null, 'hall', ['Executive Assistant III', 'Administrative Officer IV']],
        ];
    }

    /**
     * @param  array<string, Workgroup>  $workgroups
     * @param  array<string, Terminal>  $terminals
     * @param  array<string, array{schedule: Schedule, shifts: list<Shift>}>  $schedules
     * @param  array<string, list<Team>>  $teams
     * @return list<array<string, mixed>>
     */
    private function people(
        Agency $agency,
        array $workgroups,
        array $terminals,
        array $schedules,
        array $teams,
        CarbonImmutable $anchor,
        CarbonImmutable $from,
    ): array {
        $habits = $this->wheel(array_map(fn (int $share): array => ['share' => $share], self::HABITS));
        $uids = [];
        $people = [];
        $index = 0;

        foreach ($this->staffing() as [$code, $headcount, $key, $cohort, $device, $positions]) {
            $workgroup = $workgroups[$code];
            $terminal = $terminals[$device];

            for ($seat = 0; $seat < $headcount; $seat++, $index++) {
                $habit = array_keys(self::HABITS)[$habits[$index % count($habits)]];

                // Two hired inside the window and one leaving inside it, so the
                // grid and the ledgers both show a partial placement.
                [$starts, $ends] = match ($index) {
                    5 => [$from->addMonth(), null],
                    12 => [$from->addMonths(2)->addDays(3), null],
                    19 => [$anchor->subYears(4), $from->addMonths(2)->endOfMonth()->startOfDay()],
                    default => [$anchor->subYears(fake()->numberBetween(1, 15)), null],
                };

                $employee = Employee::factory()->create([
                    'agency_id' => $agency->id,
                    'position' => $positions[$seat % count($positions)],
                ]);

                $this->account($agency, $employee);

                Deployment::factory()->create([
                    'agency_id' => $agency->id,
                    'employee_id' => $employee->id,
                    'workgroup_id' => $workgroup->id,
                    'starts' => $starts->toDateString(),
                    'ends' => $ends?->toDateString(),
                ]);

                // Sequential per device, which is what a terminal actually
                // does, and it sidesteps a small unique range running out.
                $uids[$terminal->id] = ($uids[$terminal->id] ?? 0) + 1;
                $uid = str_pad((string) $uids[$terminal->id], 4, '0', STR_PAD_LEFT);

                Enrollment::factory()->create([
                    'agency_id' => $agency->id,
                    'employee_id' => $employee->id,
                    'terminal_id' => $terminal->id,
                    'uid' => $uid,
                    'starts' => $starts->toDateString(),
                    'ends' => $ends?->toDateString(),
                ]);

                $opens = $starts->lt($anchor) ? $anchor : $starts;
                $team = $cohort === null ? null : $teams[$cohort][$seat % count($teams[$cohort])];

                $roster = $team === null
                    ? Roster::factory()->create([
                        'agency_id' => $agency->id,
                        'employee_id' => $employee->id,
                        'schedule_id' => $schedules[$key]['schedule']->id,
                        'anchor' => $anchor->toDateString(),
                        'starts' => $opens->toDateString(),
                        'ends' => $ends?->toDateString(),
                    ])
                    : Roster::factory()->fromTeam($team)->create([
                        'employee_id' => $employee->id,
                        'starts' => $opens->toDateString(),
                        'ends' => $ends?->toDateString(),
                    ]);

                $people[] = [
                    'employee_id' => $employee->id,
                    'workgroup' => $code,
                    'uid' => $uid,
                    'habit' => $habit,
                    'shifts' => $team === null ? $schedules[$key]['shifts'] : $schedules[$cohort]['shifts'],
                    'anchor' => CarbonImmutable::parse($roster->anchor->toDateString()),
                    'from' => $starts,
                    'to' => $ends,
                    'terminal' => $terminal,
                ];
            }
        }

        $this->transferred($agency, $schedules, $people[0], $anchor, $from);
        $this->unrostered($agency, $workgroups);

        return $people;
    }

    /**
     * Ledger attestation resolves its `employee` and `supervisor` roles to a
     * user account linked to the person, so everyone gets one.
     */
    private function account(Agency $agency, Employee $employee): void
    {
        User::factory()->forAgency($agency)->preset(Preset::Viewer)->create([
            'employee_id' => $employee->id,
            'name' => $employee->first_name.' '.$employee->last_name,
            'email' => strtolower($employee->number).'@demo.khronoz.test',
            'password' => Hash::make('password'),
        ]);
    }

    /**
     * One person moved onto another schedule mid-window. The exclusion on
     * rosters treats both ends as inclusive, so the old one has to close the
     * day before the new one opens.
     *
     * @param  array<string, array{schedule: Schedule, shifts: list<Shift>}>  $schedules
     * @param  array<string, mixed>  $person
     */
    private function transferred(Agency $agency, array $schedules, array $person, CarbonImmutable $anchor, CarbonImmutable $from): void
    {
        $switch = $from->addMonth()->startOfMonth();

        Roster::query()
            ->where('employee_id', $person['employee_id'])
            ->whereNull('ends')
            ->update(['ends' => $switch->subDay()->toDateString()]);

        Roster::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $person['employee_id'],
            'schedule_id' => $schedules['standard']['schedule']->id,
            'anchor' => $anchor->toDateString(),
            'starts' => $switch->toDateString(),
            'ends' => null,
        ]);
    }

    /**
     * Placed but never rostered, which is the row a scheduling screen has to
     * render as empty rather than as a mistake.
     *
     * @param  array<string, Workgroup>  $workgroups
     */
    private function unrostered(Agency $agency, array $workgroups): void
    {
        $people = [
            ['GSO', 'Utility Worker I'],
            ['HRMO', 'Administrative Aide IV'],
        ];

        foreach ($people as [$code, $position]) {
            $employee = Employee::factory()->create([
                'agency_id' => $agency->id,
                'position' => $position,
            ]);

            $this->account($agency, $employee);

            Deployment::factory()->create([
                'agency_id' => $agency->id,
                'employee_id' => $employee->id,
                'workgroup_id' => $workgroups[$code]->id,
                'starts' => today()->subYear()->toDateString(),
                'ends' => null,
            ]);
        }
    }

    /**
     * A head signs their office's ledgers, so every department needs one and
     * the offices above it need one too.
     *
     * @param  array<string, Workgroup>  $workgroups
     */
    private function heads(array $workgroups): void
    {
        foreach ($workgroups as $workgroup) {
            $head = Employee::query()
                ->whereHas('deployments', fn ($query) => $query->where('workgroup_id', $workgroup->id))
                ->orderBy('number')
                ->first();

            // A section without its own senior borrows the department's, which
            // is also what the nearest-ancestor head rule does at read time.
            $head ??= Employee::query()
                ->whereHas('deployments', fn ($query) => $query->where('workgroup_id', $workgroups['OED']->id))
                ->orderBy('number')
                ->first();

            $workgroup->update(['head_id' => $head?->id]);
        }
    }

    /**
     * @param  array<string, Workgroup>  $workgroups
     * @param  list<array<string, mixed>>  $people
     */
    private function calendar(Agency $agency, array $workgroups, User $clerk, array $people, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $this->national($agency, $from, $to);

        Holiday::factory()->create([
            'agency_id' => $agency->id,
            'date' => $from->addDays(20)->toDateString(),
            'name' => 'City Charter Day',
            'type' => HolidayType::Local,
            'reference' => 'City Ordinance No. 512',
        ]);

        Holiday::factory()->create([
            'agency_id' => $agency->id,
            'date' => $from->addDays(75)->toDateString(),
            'name' => 'Feast of the Patroness',
            'type' => HolidayType::Special,
            'reference' => 'Proclamation No. 1236',
        ]);

        // Whole day, agency-wide: the shape a typhoon signal takes.
        Suspension::factory()->create([
            'agency_id' => $agency->id,
            'workgroup_id' => null,
            'date' => $from->addDays(51)->toDateString(),
            'reason' => 'Typhoon Signal No. 3',
            'user_id' => $clerk->id,
        ]);

        // A time window on one subtree exercises truncated slots and coverage.
        Suspension::factory()->forWorkgroup($workgroups['ACCTG'])->partial('12:00:00', '17:00:00')->create([
            'date' => $from->addDays(65)->toDateString(),
            'reason' => 'Water interruption',
            'user_id' => $clerk->id,
        ]);

        $span = max(1, (int) $from->diffInDays($to) - 6);

        foreach ($this->every($people, 7) as $offset => $person) {
            Exemption::factory()->spanning(3)->create([
                'agency_id' => $agency->id,
                'employee_id' => $person['employee_id'],
                'date' => $from->addDays(($offset * 5) % $span)->toDateString(),
                'type' => ExemptionType::Leave,
                'user_id' => $clerk->id,
            ]);
        }

        foreach ($this->every($people, 11) as $offset => $person) {
            Exemption::factory()->create([
                'agency_id' => $agency->id,
                'employee_id' => $person['employee_id'],
                'date' => $from->addDays(($offset * 9 + 3) % $span)->toDateString(),
                'type' => ExemptionType::Travel,
                'user_id' => $clerk->id,
            ]);
        }

        foreach ($this->every($people, 9) as $offset => $person) {
            $evening = $from->addDays(($offset * 7 + 11) % $span);

            Overtime::factory()->create([
                'agency_id' => $agency->id,
                'employee_id' => $person['employee_id'],
                'starts' => $evening->setTime(17, 0)->toDateTimeString(),
                'ends' => $evening->setTime(21, 0)->toDateTimeString(),
                'purpose' => 'Preparation of year-end reports',
                'mode' => $offset % 2 === 0 ? OvertimeMode::Pay : OvertimeMode::Cto,
                'user_id' => $clerk->id,
            ]);
        }
    }

    /**
     * Philippine holidays are generated for whatever years the window spans,
     * so the fixture never depends on the calendar it was written in. They are
     * owned by the agency rather than the platform, which keeps the demo
     * self-contained and lets the purge take them with it.
     */
    private function national(Agency $agency, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $fixed = [
            ['01-01', "New Year's Day", HolidayType::Regular],
            ['04-09', 'Araw ng Kagitingan', HolidayType::Regular],
            ['05-01', 'Labor Day', HolidayType::Regular],
            ['06-12', 'Independence Day', HolidayType::Regular],
            ['08-21', 'Ninoy Aquino Day', HolidayType::Special],
            ['11-01', "All Saints' Day", HolidayType::Special],
            ['11-30', 'Bonifacio Day', HolidayType::Regular],
            ['12-08', 'Feast of the Immaculate Conception', HolidayType::Special],
            ['12-25', 'Christmas Day', HolidayType::Regular],
            ['12-30', 'Rizal Day', HolidayType::Regular],
            ['12-31', 'Last Day of the Year', HolidayType::Special],
        ];

        for ($year = $from->year; $year <= $to->year; $year++) {
            $dates = [];

            foreach ($fixed as [$day, $name, $type]) {
                $dates[] = [CarbonImmutable::parse("{$year}-{$day}"), $name, $type];
            }

            $dates[] = [
                CarbonImmutable::parse("{$year}-08-31")->lastOfMonth(CarbonInterface::MONDAY),
                'National Heroes Day',
                HolidayType::Regular,
            ];

            foreach ($dates as [$date, $name, $type]) {
                if ($date->lt($from) || $date->gt($to)) {
                    continue;
                }

                Holiday::factory()->create([
                    'agency_id' => $agency->id,
                    'date' => $date->toDateString(),
                    'name' => $name,
                    'type' => $type,
                    'reference' => 'Proclamation No. 727',
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $people
     * @return list<array<string, mixed>>
     */
    private function every(array $people, int $nth): array
    {
        return array_values(array_filter(
            $people,
            fn (int $index): bool => $index % $nth === 0,
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * Locks the first whole month in the window. A ledger row only exists once
     * it is locked, and locking refuses anything its policy cannot resolve —
     * so a person whose signers are incomplete is counted, not fatal.
     *
     * @param  list<array<string, mixed>>  $people
     */
    private function close(Agency $agency, User $clerk, array $people, CarbonImmutable $from): void
    {
        $lock = app(LockLedger::class);
        $starts = $from->startOfMonth();
        $ends = $from->endOfMonth()->startOfDay();
        $locked = 0;
        $refused = 0;

        foreach ($people as $person) {
            $employee = Employee::find($person['employee_id']);

            if ($employee === null) {
                continue;
            }

            try {
                $lock->handle($employee, $starts, $ends, Work::All, $clerk);
                $locked++;
            } catch (ValidationException) {
                $refused++;
            }
        }

        $this->command?->getOutput()->writeln(
            "  <info>{$agency->code}</info>: locked {$locked} ledgers for {$starts->format('F Y')}"
            .($refused > 0 ? ", {$refused} refused by policy." : '.')
        );
    }

    private function report(CarbonImmutable $from, CarbonImmutable $to): void
    {
        $this->command?->getOutput()->writeln(
            '  <info>'.self::CODE.'</info>: '.$from->toFormattedDateString().' to '.$to->toFormattedDateString().'. '
            .'Sign in as <info>demo.hr@khronoz.test</info> / password.'
        );
    }
}
