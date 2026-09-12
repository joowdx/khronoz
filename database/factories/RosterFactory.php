<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Employee;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Roster>
 */
class RosterFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'schedule_id' => fn (array $attributes) => Schedule::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'team_id' => null,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-07',
            'ends' => null,
        ];
    }

    public function fromTeam(Team $team): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $team->agency_id,
            'team_id' => $team->id,
            'schedule_id' => $team->schedule_id,
            'anchor' => $team->anchor,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends' => fn (array $merged): string => CarbonImmutable::parse($merged['starts'])
                ->addDays(fake()->numberBetween(0, 365))
                ->toDateString(),
        ]);
    }
}
