<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * schedule_id is a callback for the same reason TurnFactory's is: the
     * paired FK (schedule_id, agency_id) requires the schedule to belong to
     * this team's agency.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'name' => 'Team '.strtoupper(fake()->unique()->lexify('??')),
            'schedule_id' => fn (array $attributes) => Schedule::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'anchor' => '2026-09-07',
        ];
    }

    /**
     * On $schedule with its own anchor: the hospital shape, where three teams
     * share one 21-day cycle and sit seven positions apart so every shift is
     * covered (04-scheduling.md).
     */
    public function on(Schedule $schedule, string $anchor): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $schedule->agency_id,
            'schedule_id' => $schedule->id,
            'anchor' => $anchor,
        ]);
    }
}
