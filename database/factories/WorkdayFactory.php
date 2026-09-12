<?php

namespace Database\Factories;

use App\Enums\MissingSide;
use App\Enums\WorkdayStatus;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Workday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workday>
 */
class WorkdayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'date' => '2026-09-15',
            'employee_id' => fn (array $attributes) => Employee::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'shift_id' => fn (array $attributes) => Shift::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            // Fixture shape mirrors the persisted shift snapshot.
            'shift' => fn (array $attributes) => [
                'shift' => [
                    'id' => $attributes['shift_id'],
                    'name' => 'Standard',
                    'slots' => [
                        ['in' => '08:00', 'out' => '12:00', 'window' => [-240, 180]],
                        ['in' => '13:00', 'out' => '17:00', 'window' => [-120, 300]],
                    ],
                    'required' => 480,
                    'flex' => 0,
                    'remote' => false,
                    'trust' => false,
                ],
                'settings' => [
                    'night_from' => '18:00',
                    'premium_hours' => false,
                    'suspension_charge' => true,
                    'missing_side' => MissingSide::Void->value,
                ],
                'holidays' => [],
                'suspensions' => [],
            ],
            'exemption_id' => null,
            'status' => WorkdayStatus::Present,
            'premium' => null,
            'worked' => 0,
            'credited' => 0,
            'tardy' => 0,
            'undertime' => 0,
            'excess' => 0,
            'night' => 0,
            'night_excess' => 0,
            'computed_at' => '2026-09-15 18:00:00',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Workday $workday) => $workday->refresh());
    }
}
