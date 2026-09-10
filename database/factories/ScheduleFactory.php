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
    /**
     * Define the model's default state: a seven-day cycle, the standard week.
     *
     * **A bare create() makes no turns**, and that is deliberate rather than
     * an oversight. `turns_complete` is DEFERRABLE INITIALLY DEFERRED, so it
     * fires at COMMIT — and the test suite runs inside a transaction that is
     * rolled back, so the check never runs during a test at all. A factory
     * that always wrote turns would therefore hide, not prevent, the very
     * thing the constraint exists for. Use withTurns() when a test needs a
     * resolvable cycle, and see ScheduleTest for how to make the deferred
     * constraint actually fire.
     *
     * @return array<string, mixed>
     */
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

    /**
     * A complete cycle: `length` turns at positions 0 to length - 1, which is
     * exactly what turns_complete requires.
     *
     * Five working days then two Off, wrapping for any length, so a length-7
     * schedule is the standard week and a length-21 one is a hospital
     * rotation's shape without having to name each shift. Both shifts are
     * created under this schedule's own agency, because the turns' paired FKs
     * refuse a shift from any other.
     */
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

    /** A copy of a platform-owned schedule, which is what origin_id records. */
    public function copiedFrom(Schedule $origin): static
    {
        return $this->state(fn (array $attributes): array => ['origin_id' => $origin->id]);
    }
}
