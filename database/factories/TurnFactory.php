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
    /**
     * Define the model's default state.
     *
     * schedule_id and shift_id are callbacks, not Schedule::factory() /
     * Shift::factory() directly, for the reason DeploymentFactory's are: the
     * paired FKs (schedule_id, agency_id) and (shift_id, agency_id) require
     * both parents to belong to the same agency as this turn, so each is
     * created explicitly under the agency_id resolved just above rather than
     * getting its own random one. agency_id is declared first so both
     * callbacks can read the resolved value from $attributes.
     *
     * @return array<string, mixed>
     */
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
