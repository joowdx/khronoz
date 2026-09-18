<?php

namespace Database\Seeders;

use App\Actions\LockLedger;
use App\Enums\ExemptionType;
use App\Enums\HolidayType;
use App\Enums\OvertimeMode;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Exemption;
use App\Models\Holiday;
use App\Models\Ledger;
use App\Models\Overtime;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Suspension;
use App\Models\Terminal;
use App\Models\Turn;
use App\Models\User;
use App\Models\Workgroup;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttendanceSeeder extends Seeder
{
    use SeedsAttendance;

    private const FROM = '2026-06-01';

    private const TO = '2026-08-31';

    /** A Monday, one week before the window, so every cycle starts on one. */
    private const ANCHOR = '2026-05-25';

    /** How many of an office's people carry each habit, as parts of a whole. */
    private const HABITS = [
        'punctual' => 8,
        'late' => 4,
        'undertime' => 2,
        'overtime' => 3,
        'absentee' => 2,
        'forgetful' => 1,
    ];

    public function run(): void
    {
        $this->quieten();

        $this->call(PlatformSeeder::class);

        $this->national();

        foreach ($this->offices() as [$code, $name, $headcount]) {
            $this->office($code, $name, $headcount);
        }
    }

    /** @return list<array{0: string, 1: string, 2: int}> */
    private function offices(): array
    {
        $sizes = array_values(array_filter(
            array_map('intval', explode(',', (string) getenv('SEED_OFFICES'))),
            fn (int $size): bool => $size > 0,
        ));

        return [
            ['CHO', 'City Health Office', $sizes[0] ?? 20],
            ['CGS', 'City General Services Office', $sizes[1] ?? 120],
        ];
    }

    private function national(): void
    {
        $platform = Agency::platform();

        $holidays = [
            ['2026-06-12', 'Independence Day', HolidayType::Regular, 'Proclamation No. 727'],
            ['2026-08-21', 'Ninoy Aquino Day', HolidayType::Special, 'RA 9256'],
            ['2026-08-31', 'National Heroes Day', HolidayType::Regular, 'Proclamation No. 727'],
        ];

        foreach ($holidays as [$date, $name, $type, $reference]) {
            $exists = Holiday::withoutGlobalScopes()
                ->where('agency_id', $platform->id)
                ->where('date', $date)
                ->where('name', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            Holiday::factory()->create([
                'agency_id' => $platform->id,
                'date' => $date,
                'name' => $name,
                'type' => $type,
                'reference' => $reference,
            ]);
        }
    }

    private function office(string $code, string $name, int $headcount): void
    {
        if (Agency::where('code', $code)->exists()) {
            $this->command?->getOutput()->writeln("  <comment>{$code} already seeded, skipping.</comment>");

            return;
        }

        $agency = Agency::factory()->create(['code' => $code, 'name' => $name]);

        // Set tenant context and explicit agency IDs for fixture consistency.
        app(Tenant::class)->set($agency);

        try {
            $clerk = User::factory()->forAgency($agency)->create([
                'name' => 'HR Officer',
                'email' => strtolower($code).'.hr@khronoz.test',
            ]);

            $workgroups = $this->workgroups($agency);
            $terminal = Terminal::factory()->create([
                'agency_id' => $agency->id,
                'code' => $code.'-01',
                'name' => 'Main Lobby',
            ]);

            $people = $this->people($agency, $workgroups, $this->cycles($agency, $code), $terminal, $headcount);

            $this->calendar($agency, $clerk, $workgroups, $people, $code);
            $from = CarbonImmutable::parse(self::FROM);
            $to = CarbonImmutable::parse(self::TO);

            $this->attendance($agency, $people, $from, $to);
            $this->compute($agency, $people, $from, $to);
            $this->close($agency, $code);
        } finally {
            app(Tenant::class)->forget();
        }
    }

    /**
     * @return list<Workgroup>
     */
    private function workgroups(Agency $agency): array
    {
        $office = Workgroup::factory()->create([
            'agency_id' => $agency->id,
            'kind' => 'office',
            'code' => 'OFC',
            'name' => 'Office of the Head',
        ]);

        $divisions = [];

        foreach (['ADMIN' => 'Administrative Division', 'OPS' => 'Operations Division', 'FIN' => 'Finance Division'] as $code => $name) {
            $divisions[] = Workgroup::factory()->under($office)->create([
                'kind' => 'division',
                'code' => $code,
                'name' => $name,
            ]);
        }

        return $divisions;
    }

    /**
     * @return list<array{schedule: Schedule, shifts: list<Shift>, share: int}>
     */
    private function cycles(Agency $agency, string $code): array
    {
        $off = Shift::factory()->off()->create(['agency_id' => $agency->id, 'name' => 'Rest Day']);

        if ($code === 'CHO') {
            $standard = Shift::factory()->create([
                'agency_id' => $agency->id,
                'name' => 'Standard 8-5',
                'slots' => [
                    ['in' => '08:00', 'out' => '12:00', 'window' => [-240, 180]],
                    ['in' => '13:00', 'out' => '17:00', 'window' => [-120, 300]],
                ],
                'required' => 480,
            ]);

            $flexi = Shift::factory()->create([
                'agency_id' => $agency->id,
                'name' => 'Flexitime 7-4',
                'slots' => [
                    ['in' => '07:00', 'out' => '11:00', 'window' => [-60, 240]],
                    ['in' => '12:00', 'out' => '16:00', 'window' => [-60, 300]],
                ],
                'required' => 480,
                'flex' => 120,
            ]);

            return [
                $this->cycle($agency, 'Standard week', [$standard, $standard, $standard, $standard, $standard, $off, $off], 3),
                $this->cycle($agency, 'Flexitime week', [$flexi, $flexi, $flexi, $flexi, $flexi, $off, $off], 1),
            ];
        }

        $standard = Shift::factory()->create([
            'agency_id' => $agency->id,
            'name' => 'Standard 8-5',
            'slots' => [
                ['in' => '08:00', 'out' => '12:00', 'window' => [-240, 180]],
                ['in' => '13:00', 'out' => '17:00', 'window' => [-120, 300]],
            ],
            'required' => 480,
        ]);

        // Four ten-hour days. `required` is not derived from the slots
        // (shifts.required's own comment): the timetable says when, the
        // number says what a complete day credits.
        $compressed = Shift::factory()->create([
            'agency_id' => $agency->id,
            'name' => 'Compressed 7-6',
            'slots' => [
                ['in' => '07:00', 'out' => '12:00', 'window' => [-120, 240]],
                ['in' => '13:00', 'out' => '18:00', 'window' => [-120, 300]],
            ],
            'required' => 600,
        ]);

        // `trust` true, so the device's own in/out byte decides the side
        // rather than proximity — the one place timelogs.state is read.
        $early = Shift::factory()->create([
            'agency_id' => $agency->id,
            'name' => 'Early 6-2',
            'slots' => [['in' => '06:00', 'out' => '14:00', 'window' => [-120, 240]]],
            'required' => 480,
            'trust' => true,
        ]);

        $mid = Shift::factory()->create([
            'agency_id' => $agency->id,
            'name' => 'Mid 2-10',
            'slots' => [['in' => '14:00', 'out' => '22:00', 'window' => [-120, 240]]],
            'required' => 480,
            'trust' => true,
        ]);

        // 30:00 is 06:00 the following day. slots_valid() allows an `out` up
        // to 72:00, which is the cap the whole across-midnight rule is built
        // around, and this is the shift that exercises it.
        $night = Shift::factory()->create([
            'agency_id' => $agency->id,
            'name' => 'Night 10-6',
            'slots' => [['in' => '22:00', 'out' => '30:00', 'window' => [-120, 240]]],
            'required' => 480,
            'trust' => true,
        ]);

        return [
            $this->cycle($agency, 'Standard week', [$standard, $standard, $standard, $standard, $standard, $off, $off], 6),
            $this->cycle($agency, 'Compressed week', [$compressed, $compressed, $compressed, $compressed, $off, $off, $off], 3),
            $this->cycle($agency, 'Three-shift rotation', [$early, $early, $mid, $mid, $night, $night, $off, $off], 3),
        ];
    }

    /**
     * @param  list<Shift>  $shifts  One per position, and `count()` is the length.
     * @return array{schedule: Schedule, shifts: list<Shift>, share: int}
     */
    private function cycle(Agency $agency, string $name, array $shifts, int $share): array
    {
        $schedule = DB::transaction(function () use ($agency, $name, $shifts): Schedule {
            $schedule = Schedule::factory()->create([
                'agency_id' => $agency->id,
                'name' => $name,
                'length' => count($shifts),
            ]);

            foreach ($shifts as $position => $shift) {
                Turn::factory()->create([
                    'agency_id' => $agency->id,
                    'schedule_id' => $schedule->id,
                    'shift_id' => $shift->id,
                    'position' => $position,
                ]);
            }

            return $schedule;
        });

        return ['schedule' => $schedule, 'shifts' => $shifts, 'share' => $share];
    }

    /**
     * @param  list<Workgroup>  $workgroups
     * @param  list<array{schedule: Schedule, shifts: list<Shift>, share: int}>  $cycles
     * @return list<Person>
     */
    private function people(Agency $agency, array $workgroups, array $cycles, Terminal $terminal, int $headcount): array
    {
        $wheel = $this->wheel($cycles);
        $habits = $this->wheel(array_map(fn (int $share): array => ['share' => $share], self::HABITS));
        $anchor = CarbonImmutable::parse(self::ANCHOR);
        $people = [];

        for ($index = 0; $index < $headcount; $index++) {
            $cycle = $cycles[$wheel[$index % count($wheel)]];
            $habit = array_keys(self::HABITS)[$habits[$index % count($habits)]];

            // Two hired mid-window, one departing mid-window, and everybody
            // else placed well before it.
            [$from, $to] = match (true) {
                $headcount > 100 && $index === 0 => [CarbonImmutable::parse('2026-07-01'), null],
                $headcount > 100 && $index === 1 => [CarbonImmutable::parse('2026-07-01'), null],
                $headcount > 100 && $index === 2 => [$anchor->subYears(3), CarbonImmutable::parse('2026-07-31')],
                default => [$anchor->subYears(fake()->numberBetween(1, 12)), null],
            };

            $employee = Employee::factory()->create(['agency_id' => $agency->id]);

            Deployment::factory()->create([
                'agency_id' => $agency->id,
                'employee_id' => $employee->id,
                'workgroup_id' => $workgroups[$index % count($workgroups)]->id,
                'starts' => $from->toDateString(),
                'ends' => $to?->toDateString(),
            ]);

            // Sequential per terminal, which is what a device actually does,
            // and it sidesteps fake()->unique() exhausting a small range.
            $uid = str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);

            Enrollment::factory()->create([
                'agency_id' => $agency->id,
                'employee_id' => $employee->id,
                'terminal_id' => $terminal->id,
                'uid' => $uid,
                'starts' => $from->toDateString(),
                'ends' => $to?->toDateString(),
            ]);

            Roster::factory()->create([
                'agency_id' => $agency->id,
                'employee_id' => $employee->id,
                'schedule_id' => $cycle['schedule']->id,
                'anchor' => self::ANCHOR,
                'starts' => $from->lt($anchor) ? self::ANCHOR : $from->toDateString(),
                'ends' => $to?->toDateString(),
            ]);

            // Pass IDs so computation does not retain employee models.
            $people[] = [
                'employee_id' => $employee->id,
                'uid' => $uid,
                'habit' => $habit,
                'shifts' => $cycle['shifts'],
                'anchor' => $anchor,
                'from' => $from,
                'to' => $to,
                'terminal' => $terminal,
            ];
        }

        return $people;
    }

    /**
     * @param  list<Workgroup>  $workgroups
     * @param  list<Person>  $people
     */
    private function calendar(Agency $agency, User $clerk, array $workgroups, array $people, string $code): void
    {
        Holiday::factory()->create([
            'agency_id' => $agency->id,
            'date' => $code === 'CHO' ? '2026-07-16' : '2026-06-24',
            'name' => $code === 'CHO' ? 'Charter Day' : 'Foundation Day',
            'type' => HolidayType::Local,
            'reference' => 'City Ordinance No. '.fake()->numberBetween(100, 999),
        ]);

        // Whole day, agency-wide: the shape a typhoon signal takes.
        Suspension::factory()->create([
            'agency_id' => $agency->id,
            'workgroup_id' => null,
            'date' => '2026-07-22',
            'reason' => 'Typhoon Signal No. 3',
            'user_id' => $clerk->id,
        ]);

        // The paired time window exercises truncated slots and subtree coverage.
        Suspension::factory()->create([
            'agency_id' => $agency->id,
            'workgroup_id' => $workgroups[0]->id,
            'date' => '2026-08-05',
            'starts' => '12:00:00',
            'ends' => '17:00:00',
            'reason' => 'Water interruption',
            'user_id' => $clerk->id,
        ]);

        foreach ($this->every($people, 7) as $offset => $person) {
            Exemption::factory()->spanning(3)->create([
                'agency_id' => $agency->id,
                'employee_id' => $person['employee_id'],
                'date' => CarbonImmutable::parse('2026-06-15')->addDays($offset * 3)->toDateString(),
                'type' => ExemptionType::Leave,
                'user_id' => $clerk->id,
            ]);
        }

        foreach ($this->every($people, 11) as $offset => $person) {
            Exemption::factory()->create([
                'agency_id' => $agency->id,
                'employee_id' => $person['employee_id'],
                'date' => CarbonImmutable::parse('2026-07-06')->addDays($offset * 2)->toDateString(),
                'type' => ExemptionType::Travel,
                'user_id' => $clerk->id,
            ]);
        }

        foreach ($this->every($people, 9) as $offset => $person) {
            $evening = CarbonImmutable::parse('2026-08-10')->addDays($offset);

            Overtime::factory()->create([
                'agency_id' => $agency->id,
                'employee_id' => $person['employee_id'],
                'starts' => $evening->setTime(17, 0)->toDateTimeString(),
                'ends' => $evening->setTime(21, 0)->toDateTimeString(),
                'purpose' => 'Year-end preparation of accounts',
                'mode' => $offset % 2 === 0 ? OvertimeMode::Pay : OvertimeMode::Cto,
                'user_id' => $clerk->id,
            ]);
        }
    }

    /**
     * @param  list<Person>  $people
     * @return list<Person>
     */
    private function every(array $people, int $nth): array
    {
        return array_values(array_filter(
            $people,
            fn (int $index): bool => $index % $nth === 0,
            ARRAY_FILTER_USE_KEY,
        ));
    }

    private function close(Agency $agency, string $code): void
    {
        if ($code !== 'CHO') {
            return;
        }

        $lock = app(LockLedger::class);

        $ledgers = Ledger::query()
            ->where('agency_id', $agency->id)
            ->where('month', '2026-06-01')
            ->get();

        foreach ($ledgers as $ledger) {
            $lock->handle($ledger);
        }

        $this->command?->getOutput()->writeln("  <info>{$agency->code}</info>: locked {$ledgers->count()} June ledgers.");
    }
}
/** @return void */
