<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Turn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Turn>
 */
class TurnFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'schedule_id' => fn (array $attributes) => Schedule::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'shift_id' => fn (array $attributes) => Shift::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'position' => 0,
        ];
    }
}
