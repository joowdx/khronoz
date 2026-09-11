<?php

namespace Database\Seeders;

use App\Actions\ImportTimelogs;
use App\Actions\LockLedger;
use App\Attendance\Computer;
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
use App\Support\Settings;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Three months of two working offices — June to August 2026, ending before
 * `today()` so every month is complete and lockable.
 *
 * **Not called by `DatabaseSeeder`.** It writes roughly forty thousand
 * timelogs and computes thirteen thousand workdays, which is minutes rather
 * than seconds, and a `db:seed` that slow makes resetting the database
 * something you avoid doing. Run it deliberately:
 *
 *     php artisan db:seed --class=AttendanceSeeder
 *
 * **It seeds facts and then runs the engine over them**, rather than writing
 * `workdays` and `punches` directly. A hand-written workday is a fixture of a
 * shape the code owns, and `.ai/rules/factories.md` records what that costs:
 * `WorkdayFactory` invented a flat `shift` json, every assertion passed
 * against the fiction, and the column was empty on every real row. So the
 * timelogs go in through `ImportTimelogs` — the actual ingestion path, which
 * leaves real `syncs` rows behind — and `Computer` derives everything else.
 * What comes out is a database indistinguishable from one a device filled.
 *
 * **The two offices differ in both halves of the question.** Their
 * *schedules* differ: the health office is a plain five-day week with a
 * flexitime group, and general services runs a compressed four-day week and a
 * three-shift rotation whose night turn crosses midnight, so night
 * differential, the 72-hour slot cap and cross-month attribution all get
 * exercised. Their *attendance* differs by person: each employee is given one
 * of six habits and keeps it, so the ledgers show a spread of tardiness,
 * undertime, absence and overtime instead of one uniform office. Three
 * employees also start or leave mid-window, which is decision 82's employment
 * gate seen from the data side.
 *
 * Guarded on the agency code, like the sample organization in
 * `DatabaseSeeder`: running it twice touches nothing the second time.
 */
class AttendanceSeeder extends Seeder
{
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

    /**
     * The offices to build, as `[code, name, headcount]`.
     *
     * Twenty and a hundred and twenty by default, which is the shape asked
     * for and runs in under four minutes. Both sizes are overridable, because
     * most of that time is the second office and most days you do not need
     * it:
     *
     *     SEED_OFFICES=20,15 php artisan db:seed --class=AttendanceSeeder
     *
     * `getenv()` rather than `env()`: `env()` returns null once the config is
     * cached, and a seeder that silently ignores its own knob is worse than
     * one without it.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
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

    /**
     * Stop Telescope recording for the duration.
     *
     * It buffers every query as an entry in memory and flushes at the end of
     * the command, and this seeder issues something like eighty thousand:
     * `db:seed` died on the default 128M `memory_limit` inside the first
     * office's compute, with a fatal error that leaves a half-built agency
     * the guard above then skips forever. `stopRecording()` is the documented
     * remedy for bulk console work, and a seeder is not something anybody
     * wants to read back entry by entry.
     *
     * `class_exists`, and the name as a string: Telescope is a dev dependency
     * (`AppServiceProvider` registers it only outside production) and this
     * file ships either way. The test suite never met this — `phpunit.xml`
     * sets `TELESCOPE_ENABLED=false`.
     */
    private function quieten(): void
    {
        /** @var class-string $telescope */
        $telescope = 'Laravel\Telescope\Telescope';

        if (class_exists($telescope)) {
            $telescope::stopRecording();
        }
    }

    /**
     * The three national holidays that actually fall in the window, owned by
     * the platform agency so both offices inherit them through
     * `AgencyOrPlatformScope`.
     *
     * Created **before** any tenant is set: `BelongsToAgency` refuses an
     * explicit `agency_id` that disagrees with a set tenant, which is the
     * ordering trap `HolidayFactory::national()` documents.
     */
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

        // Set as tenant *and* pass agency_id explicitly on every row below.
        // The tenant is what ImportTimelogs and Computer read; the explicit
        // column is factories.md's rule, and a seeded row gets no free pass
        // around it just because a seeder is creating it.
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
            $this->attendance($agency, $terminal, $people);
            $this->compute($agency, $people);
            $this->close($agency, $code);
        } finally {
            app(Tenant::class)->forget();
        }
    }

    /**
     * A two-level tree. Deep enough that a workgroup-scoped suspension has
     * descendants to reach, which is the only structural thing the engine
     * asks of an org chart.
     *
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
     * The cycles an office runs, as `[schedule, shifts by position]`.
     *
     * The shifts are carried alongside the schedule because the punch
     * generator has to know what a given date expects, and asking the
     * pipeline would be circular — the pipeline is the thing this data
     * exists to feed. Position is `(date − anchor) mod length`, the same
     * arithmetic `Cycle` does.
     *
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
     * One schedule and its complete run of turns.
     *
     * Written out rather than reached through `ScheduleFactory::withTurns()`,
     * which hard-codes five working days and two off — true of a standard
     * week and of neither of the other two shapes here.
     *
     * **In a transaction, and it has to be.** `turns_complete` is DEFERRABLE
     * INITIALLY DEFERRED, so it is checked at COMMIT — and outside an explicit
     * transaction every statement commits on its own, which means the bare
     * `schedules` insert is a complete unit of work with no turns in it and
     * raises P0001 before the first turn is written. The test suite never sees
     * this because `RefreshDatabase` holds one transaction open around each
     * test; a seeder is the first caller that runs without one.
     *
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
     * The people, each with a placement, a device enrollment, a roster and a
     * habit they keep for the whole quarter.
     *
     * Three of them move: two are hired on 1 July and one leaves on 31 July.
     * Their days outside the placement must come out of the engine as no
     * workday at all (decision 82), and a seeder that hires everybody on the
     * same distant Monday never shows whether that holds.
     *
     * @param  list<Workgroup>  $workgroups
     * @param  list<array{schedule: Schedule, shifts: list<Shift>, share: int}>  $cycles
     * @return list<array{employee_id: string, uid: string, habit: string, shifts: list<Shift>, from: CarbonImmutable, to: ?CarbonImmutable}>
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

            // The id, not the model. `compute()` loads each employee on its
            // own and lets it go again: an office of 120 held here is 120
            // object graphs alive for the whole run, and the compute is the
            // phase with no memory to spare.
            $people[] = [
                'employee_id' => $employee->id,
                'uid' => $uid,
                'habit' => $habit,
                'shifts' => $cycle['shifts'],
                'from' => $from,
                'to' => $to,
            ];
        }

        return $people;
    }

    /**
     * A flat list of indices repeated by share, so `$wheel[$n % count]` deals
     * the mix out round-robin instead of by a random draw that can miss a
     * whole cycle in a twenty-person office.
     *
     * @param  array<array-key, array{share: int}>  $weighted
     * @return list<int>
     */
    private function wheel(array $weighted): array
    {
        $wheel = [];

        foreach (array_values($weighted) as $index => $entry) {
            $wheel = [...$wheel, ...array_fill(0, $entry['share'], $index)];
        }

        return $wheel;
    }

    /**
     * The calendar the office writes for itself: a local holiday, two
     * suspensions, leave slips and overtime authorities.
     *
     * @param  list<Workgroup>  $workgroups
     * @param  list<array{employee_id: string, uid: string, habit: string, shifts: list<Shift>, from: CarbonImmutable, to: ?CarbonImmutable}>  $people
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

        // Noon to five, one division only — so the truncated-slot path and
        // the workgroup subtree both get walked. A *pair*: `starts` without
        // `ends` is refused by suspensions_hours_paired, because "suspended
        // from noon until nothing" is something the deriver would have to
        // guess at.
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
     * Every nth person, reindexed from zero so the caller can space their
     * dates by the offset.
     *
     * @param  list<array{employee_id: string, uid: string, habit: string, shifts: list<Shift>, from: CarbonImmutable, to: ?CarbonImmutable}>  $people
     * @return list<array{employee_id: string, uid: string, habit: string, shifts: list<Shift>, from: CarbonImmutable, to: ?CarbonImmutable}>
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
     * Write the quarter as a device attlog and feed it to the real importer.
     *
     * A file and `ImportTimelogs`, not `Timelog::factory()`: the factory
     * makes a `Sync` per row, which is forty thousand runs of a device that
     * ran twice; the importer chunks, dedupes on the attlog natural key and
     * leaves one honest `syncs` row behind. It is also the path a bug would
     * actually be in.
     *
     * @param  list<array{employee_id: string, uid: string, habit: string, shifts: list<Shift>, from: CarbonImmutable, to: ?CarbonImmutable}>  $people
     */
    private function attendance(Agency $agency, Terminal $terminal, array $people): void
    {
        $path = tempnam(sys_get_temp_dir(), 'khronoz-attlog-');
        $handle = fopen($path, 'w');
        $anchor = CarbonImmutable::parse(self::ANCHOR);
        $from = CarbonImmutable::parse(self::FROM);
        $to = CarbonImmutable::parse(self::TO);
        $lines = 0;

        try {
            foreach ($people as $person) {
                for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                    if ($date->lt($person['from']) || ($person['to'] !== null && $date->gt($person['to']))) {
                        continue;
                    }

                    foreach ($this->punches($person, $anchor, $date) as [$time, $state]) {
                        fwrite($handle, "{$person['uid']}\t{$time}\t{$state}\t1\n");
                        $lines++;
                    }
                }
            }

            fclose($handle);

            $this->command?->getOutput()->writeln("  <info>{$agency->code}</info>: importing {$lines} timelogs…");

            app(ImportTimelogs::class)->handle($terminal, $path, "attlog-{$agency->code}.dat");
        } finally {
            @unlink($path);
        }
    }

    /**
     * One day of one person's punches, as `[timestamp, state]` pairs.
     *
     * The habit is the whole point: an office where everybody arrives at
     * 07:59 produces ninety-two identical workdays and a DTR that proves
     * nothing. `state` is the device's own in/out byte — 0 and 1 — which the
     * three-shift rotation's `trust` shifts read and the rest ignore.
     *
     * @param  array{employee_id: string, uid: string, habit: string, shifts: list<Shift>, from: CarbonImmutable, to: ?CarbonImmutable}  $person
     * @return list<array{0: string, 1: int}>
     */
    private function punches(array $person, CarbonImmutable $anchor, CarbonImmutable $date): array
    {
        $shifts = $person['shifts'];
        $shift = $shifts[$anchor->diffInDays($date) % count($shifts)];
        $slots = $shift->slots;

        // A rest day nobody was called in on, which is most of them. When
        // somebody is, the day has no expectation at all: the taps become
        // transits and every minute falls to `excess` (daily rule 10,
        // decision 78), which is the premium-day path and the one an office
        // of pure Mondays never walks.
        if ($slots === []) {
            if ($person['habit'] !== 'overtime' || ! fake()->boolean(8)) {
                return [];
            }

            $in = $this->at($date, '08:00')->addMinutes(fake()->numberBetween(-10, 20));

            return [
                [$in->toDateTimeString(), 0],
                [$in->addMinutes(fake()->numberBetween(240, 540))->toDateTimeString(), 1],
            ];
        }

        if ($person['habit'] === 'absentee' && fake()->boolean(12)) {
            return [];
        }

        // Everybody misses the occasional day; the exemptions above excuse
        // some of them and the rest are the absences a DTR is meant to show.
        if (fake()->boolean(3)) {
            return [];
        }

        $punches = [];
        $last = count($slots) - 1;

        foreach ($slots as $index => $slot) {
            $in = $this->at($date, $slot['in'])->addMinutes($this->arrival($person['habit']));
            $out = $this->at($date, $slot['out'])->addMinutes($this->departure($person['habit'], $index === $last));

            $punches[] = [$in->toDateTimeString(), 0];
            $punches[] = [$out->toDateTimeString(), 1];
        }

        // A missed side, which is what settings.missing_side exists to
        // answer (daily rule 4) and what nothing in a tidy fixture produces.
        if ($person['habit'] === 'forgetful' && fake()->boolean(10)) {
            array_splice($punches, fake()->numberBetween(0, count($punches) - 1), 1);
        }

        return $punches;
    }

    /**
     * A slot's clock time on `$date`, where an hour past 24 is the next day —
     * `30:00` is 06:00 tomorrow, which is how a night shift is written and
     * what `slots_valid()`'s 72-hour ceiling is for.
     */
    private function at(CarbonImmutable $date, string $clock): CarbonImmutable
    {
        [$hours, $minutes] = array_map('intval', explode(':', $clock));

        return $date->startOfDay()->addMinutes($hours * 60 + $minutes);
    }

    private function arrival(string $habit): int
    {
        return match ($habit) {
            'late' => fake()->boolean(60) ? fake()->numberBetween(8, 45) : fake()->numberBetween(-10, 0),
            'undertime' => fake()->numberBetween(-10, 0),
            default => fake()->numberBetween(-15, -1),
        };
    }

    private function departure(string $habit, bool $last): int
    {
        return match (true) {
            $habit === 'overtime' && $last => fake()->numberBetween(60, 180),
            $habit === 'undertime' && $last => -fake()->numberBetween(20, 60),
            default => fake()->numberBetween(0, 10),
        };
    }

    /**
     * Run the engine over the whole window, one employee at a time.
     *
     * Directly rather than through `RecomputeWorkdays`, and the reason is
     * determinism: the job's outcome depends on `QUEUE_CONNECTION`, so on a
     * `database` queue the seeder would finish having computed nothing and
     * report success. `Computer` is what the job calls anyway.
     *
     * @param  list<array{employee_id: string, uid: string, habit: string, shifts: list<Shift>, from: CarbonImmutable, to: ?CarbonImmutable}>  $people
     */
    private function compute(Agency $agency, array $people): void
    {
        $settings = new Settings($agency);
        $from = CarbonImmutable::parse(self::FROM);
        $to = CarbonImmutable::parse(self::TO);
        $output = $this->command?->getOutput();

        $output?->writeln("  <info>{$agency->code}</info>: computing ".count($people).' employees…');
        $output?->progressStart(count($people));

        foreach ($people as $person) {
            $employee = Employee::findOrFail($person['employee_id']);

            (new Computer($employee, $settings))->over($from, $to);

            unset($employee);
            $output?->progressAdvance();
        }

        $output?->progressFinish();
    }

    /**
     * Lock June for the smaller office, so the demo has a signed month as
     * well as open ones — the freeze triggers of decisions 55, 80 and 81 are
     * invisible until something is actually locked.
     *
     * `LockLedger`, not an `update()`: `ledgers_lock_complete` refuses a lock
     * while a punch is still due, and going through the action means the
     * seeder finds that out the same way a user would.
     */
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
