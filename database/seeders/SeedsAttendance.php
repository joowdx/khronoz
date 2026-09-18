<?php

namespace Database\Seeders;

use App\Actions\ImportTimelogs;
use App\Attendance\Computer;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Terminal;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * Synthesizes device attendance for a seeded agency and computes it.
 *
 * A person is the minimum a punch generator needs, carried as ids so no
 * Employee model is retained across the window:
 *
 * @mixin \Illuminate\Database\Seeder
 *
 * @phpstan-type Person array{
 *     employee_id: string,
 *     uid: string,
 *     habit: string,
 *     shifts: list<Shift>,
 *     anchor: CarbonImmutable,
 *     from: CarbonImmutable,
 *     to: ?CarbonImmutable,
 *     terminal: Terminal,
 * }
 */
trait SeedsAttendance
{
    /**
     * Telescope buffers every query as an in-memory entry until the command
     * ends; a bulk seeder issues tens of thousands and dies on the default
     * memory_limit, leaving a half-built agency an existence guard then skips
     * forever. It is a dev dependency, hence the string class name.
     */
    protected function quieten(): void
    {
        /** @var class-string $telescope */
        $telescope = 'Laravel\Telescope\Telescope';

        if (class_exists($telescope)) {
            $telescope::stopRecording();
        }
    }

    /**
     * @param  array<array-key, array{share: int}>  $weighted
     * @return list<int>
     */
    protected function wheel(array $weighted): array
    {
        $wheel = [];

        foreach (array_values($weighted) as $index => $entry) {
            $wheel = [...$wheel, ...array_fill(0, $entry['share'], $index)];
        }

        return $wheel;
    }

    /**
     * @param  list<Person>  $people
     */
    protected function attendance(Agency $agency, array $people, CarbonImmutable $from, CarbonImmutable $to): void
    {
        foreach ($this->byTerminal($people) as $group) {
            $this->attlog($agency, $group['terminal'], $group['people'], $from, $to);
        }
    }

    /**
     * One device writes one file, which is what an import actually receives.
     *
     * @param  list<Person>  $people
     * @return list<array{terminal: Terminal, people: list<Person>}>
     */
    private function byTerminal(array $people): array
    {
        $groups = [];

        foreach ($people as $person) {
            $id = $person['terminal']->id;

            $groups[$id] ??= ['terminal' => $person['terminal'], 'people' => []];
            $groups[$id]['people'][] = $person;
        }

        return array_values($groups);
    }

    /**
     * @param  list<Person>  $people
     */
    private function attlog(Agency $agency, Terminal $terminal, array $people, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $path = tempnam(sys_get_temp_dir(), 'khronoz-attlog-');
        $handle = fopen($path, 'w');
        $lines = 0;

        try {
            foreach ($people as $person) {
                for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                    if ($date->lt($person['from']) || ($person['to'] !== null && $date->gt($person['to']))) {
                        continue;
                    }

                    foreach ($this->punches($person, $date) as [$time, $state]) {
                        fwrite($handle, "{$person['uid']}\t{$time}\t{$state}\t1\n");
                        $lines++;
                    }
                }
            }

            fclose($handle);

            $this->command?->getOutput()->writeln("  <info>{$agency->code}</info>: importing {$lines} timelogs from {$terminal->code}…");

            app(ImportTimelogs::class)->handle($terminal, $path, "attlog-{$terminal->code}.dat");
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  Person  $person
     * @return list<array{0: string, 1: int}>
     */
    protected function punches(array $person, CarbonImmutable $date): array
    {
        $shifts = $person['shifts'];

        // A team anchor may sit after the window opens — that is what staggers
        // a rotation — so the cycle day has to wrap on the negative side too.
        $length = count($shifts);
        $elapsed = (int) $person['anchor']->diffInDays($date);
        $shift = $shifts[(($elapsed % $length) + $length) % $length];
        $slots = $shift->slots;

        // An empty slot array is a rest day or a remote day; neither expects a
        // punch, so only the overtime habit occasionally shows up to work.
        if ($slots === []) {
            if ($shift->remote || $person['habit'] !== 'overtime' || ! fake()->boolean(8)) {
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

        // Everybody misses the occasional day; exemptions excuse some of them
        // and the rest are the absences a DTR is meant to show.
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

        // A missed side, which is what settings.missing_side exists to answer
        // and what nothing in a tidy fixture produces.
        if ($person['habit'] === 'forgetful' && fake()->boolean(10)) {
            array_splice($punches, fake()->numberBetween(0, count($punches) - 1), 1);
        }

        return $punches;
    }

    /**
     * Hours past 24 are the following day, which is how a shift crosses
     * midnight; adding minutes to the day's start resolves both alike.
     */
    protected function at(CarbonImmutable $date, string $clock): CarbonImmutable
    {
        [$hours, $minutes] = array_map('intval', explode(':', $clock));

        return $date->startOfDay()->addMinutes($hours * 60 + $minutes);
    }

    protected function arrival(string $habit): int
    {
        return match ($habit) {
            'late' => fake()->boolean(60) ? fake()->numberBetween(8, 45) : fake()->numberBetween(-10, 0),
            'undertime' => fake()->numberBetween(-10, 0),
            default => fake()->numberBetween(-15, -1),
        };
    }

    protected function departure(string $habit, bool $last): int
    {
        return match (true) {
            $habit === 'overtime' && $last => fake()->numberBetween(60, 180),
            $habit === 'undertime' && $last => -fake()->numberBetween(20, 60),
            default => fake()->numberBetween(0, 10),
        };
    }

    /**
     * @param  list<Person>  $people
     */
    protected function compute(Agency $agency, array $people, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $settings = new Settings($agency);
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
}
