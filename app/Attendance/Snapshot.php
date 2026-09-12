<?php

namespace App\Attendance;

use App\Models\Holiday;
use App\Models\Suspension;
use App\Support\Settings;

final class Snapshot
{
    /**
     * @param  iterable<int, Holiday>  $holidays
     * @param  iterable<int, Suspension>  $suspensions
     * @return array{
     * shift: ?array{id: string, name: string, slots: mixed, required: int, flex: int, remote: bool, trust: bool},
     * settings: array{night_from: string, premium_hours: bool, suspension_charge: bool, missing_side: string},
     * holidays: list<array{id: string, name: string, type: string}>,
     * suspensions: list<array{id: string, starts: ?string, ends: ?string, declared_at: ?string}>
     * }
     */
    public static function of(Day $day, Settings $settings, iterable $holidays, iterable $suspensions): array
    {
        return [
            'shift' => $day->shift === null ? null : [
                'id' => $day->shift->id,
                'name' => $day->shift->name,
                'slots' => $day->shift->slots,
                'required' => (int) $day->shift->required,
                'flex' => (int) $day->shift->flex,
                'remote' => (bool) $day->shift->remote,
                'trust' => (bool) $day->shift->trust,
            ],
            'settings' => [
                'night_from' => $settings->nightFrom(),
                'premium_hours' => $settings->premiumHours(),
                'suspension_charge' => $settings->suspensionCharge(),
                'missing_side' => $settings->missingSide()->value,
            ],
            'holidays' => collect($holidays)
                ->map(fn (Holiday $holiday): array => [
                    'id' => $holiday->id,
                    'name' => $holiday->name,
                    'type' => $holiday->type->value,
                ])
                ->values()
                ->all(),
            'suspensions' => collect($suspensions)
                ->map(fn (Suspension $suspension): array => [
                    'id' => $suspension->id,
                    'starts' => $suspension->starts,
                    'ends' => $suspension->ends,
                    'declared_at' => $suspension->declared_at?->format('Y-m-d H:i:s'),
                ])
                ->values()
                ->all(),
        ];
    }
}
