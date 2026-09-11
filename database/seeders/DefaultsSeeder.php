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

/**
 * The tier-2 set: shifts and schedules the platform agency owns and every
 * agency copies at onboarding (04-scheduling.md rule 7).
 *
 * These are **product data**, not a fixture. `CopyDefaults` deep-copies them
 * into each new agency with `origin_id` pointing back here, which is what lets
 * the defaults screen show a copy has diverged and offer to refresh it. An
 * agency edits its own copies; nothing here is edited through a request, and
 * `origin_is_platform` refuses an `origin_id` naming anything but a row of this
 * agency.
 *
 * Idempotent on name, so a re-seed after adding one default writes only the new
 * row — the same property `CopyDefaults` has, and for the same reason: this
 * runs on every deployment, not once.
 *
 * Colours are assigned deliberately rather than by the lowest-free rule, so the
 * families read as families in the roster grid: the ordinary day and its
 * flexitime variants share slot 6, the compressed week takes 3, Ramadan 4.
 * A copy carries the origin's index (rule 8), so every agency's grid agrees.
 */
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

    /** @return array<string, Shift> */
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
     * The same day, later by `$minutes`. Windows travel with their slot: a
     * window is relative to the time it guards, not to the clock.
     *
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

    /** @param  array<string, Shift>  $shifts */
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

        // A compressed week's rest days are what makes the fallback necessary:
        // a holiday landing on one reverts the rest of that ISO week to eight
        // hour days (rule 5), so every CWW schedule names Standard as its
        // fallback and none of the uncompressed ones do.
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
     * One schedule and its complete run of turns.
     *
     * `turns_complete` is DEFERRABLE INITIALLY DEFERRED, so the schedule and
     * every one of its turns must land before the transaction commits — which
     * `run()`'s single transaction already guarantees for the whole set.
     *
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
