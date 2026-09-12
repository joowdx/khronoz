<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Turn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'name' => 'Standard week '.fake()->unique()->lexify('????'),
            'length' => 7,
            'fallback_shift_id' => null,
            'origin_id' => null,
        ];
    }

    public function withTurns(?Shift $working = null, ?Shift $off = null): static
    {
        return $this->afterCreating(function (Schedule $schedule) use ($working, $off): void {
            $working ??= Shift::factory()->create(['agency_id' => $schedule->agency_id]);
            $off ??= Shift::factory()->off()->create(['agency_id' => $schedule->agency_id]);

            for ($position = 0; $position < $schedule->length; $position++) {
                Turn::factory()->create([
                    'agency_id' => $schedule->agency_id,
                    'schedule_id' => $schedule->id,
                    'shift_id' => $position % 7 < 5 ? $working->id : $off->id,
                    'position' => $position,
                ]);
            }
        });
    }

    public function copiedFrom(Schedule $origin): static
    {
        return $this->state(fn (array $attributes): array => ['origin_id' => $origin->id]);
    }
}
