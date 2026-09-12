<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\NotPlatformScope;
use App\Models\Shift;
use App\Models\Turn;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefaultsSeeder extends Seeder
{
    /** The arrival band MC 06 s. 2022 grants, as seven published options. */
    private const FLEXI_STARTS = ['07:00', '07:30', '08:00', '08:30', '09:00', '09:30', '10:00'];

    public function run(): void
    {
        $platform = Agency::withoutGlobalScope(NotPlatformScope::class)->where('platform', true)->firstOrFail();

        DB::transaction(function () use ($platform): void {
            $shifts = $this->shifts($platform);
            $this->schedules($platform, $shifts);
        });
    }

    /**
     * @return array<string, Shift>
     */
    private function shifts(Agency $platform): array
    {
        $standard = [
            ['in' => '08:00', 'out' => '12:00', 'grace' => 0, 'window' => [-240, 180]],
            ['in' => '13:00', 'out' => '17:00', 'grace' => 0, 'window' => [-120, 300]],
        ];

        $shifts = [
            // Rule XVII §5 — the universal ordinary day.
            'Standard' => ['slots' => $standard, 'required' => 480, 'flex' => 0, 'color' => 6],
            // MC 06 s. 2022. The sliding one: arrival anywhere in the band
            // moves the whole day with it, capped at 180 and floored at 0
            // (decision 59), so an early arrival cannot buy an early departure.
            'Flexi' => [
                'slots' => [
                    ['in' => '07:00', 'out' => '11:00', 'grace' => 0, 'window' => [-30, 240]],
                    ['in' => '12:00', 'out' => '16:00', 'grace' => 0, 'window' => [-60, 360]],
                ],
                'required' => 480,
                'flex' => 180,
                'color' => 6,
            ],
            // Res. 2600838 — ten-hour days, four of them.
            'Long 07:00 to 18:00' => [
                'slots' => [
                    ['in' => '07:00', 'out' => '12:00', 'grace' => 0, 'window' => [-240, 180]],
                    ['in' => '13:00', 'out' => '18:00', 'grace' => 0, 'window' => [-120, 300]],
                ],
                'required' => 600,
                'flex' => 0,
                'color' => 3,
            ],
            'Long 08:00 to 19:00' => [
                'slots' => [
                    ['in' => '08:00', 'out' => '13:00', 'grace' => 0, 'window' => [-240, 180]],
                    ['in' => '14:00', 'out' => '19:00', 'grace' => 0, 'window' => [-120, 300]],
                ],
                'required' => 600,
                'flex' => 0,
                'color' => 3,
            ],
            // Res. 81-1277 / 00-0227. One unbroken stretch: no lunch punch.
            'Ramadan' => [
                'slots' => [['in' => '07:30', 'out' => '15:30', 'grace' => 0, 'window' => [-120, 240]]],
                'required' => 480,
                'flex' => 0,
                'color' => 4,
            ],
        ];

        // The seven published arrival options. Each is a fixed day once chosen,
        // so `flex` is 0 — the choice is the flexibility, not the clock.
        foreach (self::FLEXI_STARTS as $start) {
            $hour = (int) substr($start, 0, 2);
            $half = substr($start, 3, 2) === '30';

            $shifts['Flexi '.$start] = [
                'slots' => $this->slide($standard, ($hour - 8) * 60 + ($half ? 30 : 0)),
                'required' => 480,
                'flex' => 0,
                'color' => 6,
            ];
        }

        // Neither takes a ramp index — one is a hatch, the other a dashed box —
        // but `color` is NOT NULL, so it is set and never read.
        $shifts['Off'] = ['slots' => [], 'required' => 0, 'flex' => 0, 'color' => 1, 'remote' => false];
        $shifts['Remote'] = ['slots' => [], 'required' => 480, 'flex' => 0, 'color' => 1, 'remote' => true];

        $created = [];

        foreach ($shifts as $name => $attributes) {
            $created[$name] = Shift::withoutGlobalScope(AgencyScope::class)->firstOrCreate(
                ['agency_id' => $platform->id, 'name' => $name],
                $attributes + ['remote' => false, 'trust' => false],
            );
        }

        return $created;
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @return array<int, array<string, mixed>>
     */
    private function slide(array $slots, int $minutes): array
    {
        return array_map(function (array $slot) use ($minutes): array {
            $slot['in'] = $this->shiftTime($slot['in'], $minutes);
            $slot['out'] = $this->shiftTime($slot['out'], $minutes);

            return $slot;
        }, $slots);
    }

    private function shiftTime(string $time, int $minutes): string
    {
        [$hours, $mins] = array_map('intval', explode(':', $time));
        $total = $hours * 60 + $mins + $minutes;

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    /**
     * @param  array<string, Shift>  $shifts
     */
    private function schedules(Agency $platform, array $shifts): void
    {
        $standard = $shifts['Standard'];
        $flexi = $shifts['Flexi'];
        $long = $shifts['Long 07:00 to 18:00'];
        $off = $shifts['Off'];
        $remote = $shifts['Remote'];

        $week = fn (Shift $working, int $days = 5): array => array_merge(
            array_fill(0, $days, $working),
            array_fill(0, 7 - $days, $off),
        );

        $this->cycle($platform, 'Standard week', $week($standard));
        $this->cycle($platform, 'Flexitime week', $week($flexi));

        // Compressed schedules use Standard after rest-day holidays.
        $this->cycle($platform, 'Compressed week, Monday to Thursday', [
            $long, $long, $long, $long, $off, $off, $off,
        ], $standard);

        $this->cycle($platform, 'Compressed week, Tuesday to Friday', [
            $off, $long, $long, $long, $long, $off, $off,
        ], $standard);

        $this->cycle($platform, 'Compressed week, Wednesday off', [
            $long, $long, $off, $long, $long, $off, $off,
        ], $standard);

        // OP MC 114's Flexiplace variant: four compressed days and a remote one.
        $this->cycle($platform, 'Compressed week with remote Friday', [
            $long, $long, $long, $long, $remote, $off, $off,
        ], $standard);
    }

    /**
     * @param  array<int, Shift>  $turns
     */
    private function cycle(Agency $platform, string $name, array $turns, ?Shift $fallback = null): void
    {
        $existing = Schedule::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $platform->id)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return;
        }

        $schedule = Schedule::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $platform->id,
            'name' => $name,
            'length' => count($turns),
            'fallback_shift_id' => $fallback?->id,
        ]);

        foreach ($turns as $position => $shift) {
            Turn::withoutGlobalScope(AgencyScope::class)->create([
                'agency_id' => $platform->id,
                'schedule_id' => $schedule->id,
                'shift_id' => $shift->id,
                'position' => $position,
            ]);
        }
    }
}
