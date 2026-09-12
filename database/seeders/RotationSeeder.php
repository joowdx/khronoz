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
