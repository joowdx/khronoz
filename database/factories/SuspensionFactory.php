<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Suspension;
use App\Models\User;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Suspension>
 */
class SuspensionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'workgroup_id' => null,
            'date' => '2026-09-15',
            'starts' => null,
            'ends' => null,
            'reason' => 'Typhoon '.fake()->firstName(),
            'reference' => 'Memorandum No. '.fake()->numberBetween(10, 99),
            'user_id' => fn (array $attributes) => User::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'declared_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['date'])->setTime(6, 0),
        ];
    }

    public function forWorkgroup(Workgroup $workgroup): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $workgroup->agency_id,
            'workgroup_id' => $workgroup->id,
        ]);
    }

    public function partial(string $starts = '12:00:00', string $ends = '17:00:00'): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts' => $starts,
            'ends' => $ends,
        ]);
    }
}
