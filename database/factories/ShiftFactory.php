<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'name' => 'Standard '.fake()->unique()->lexify('????'),
            'slots' => [
                ['in' => '08:00', 'out' => '12:00', 'window' => [-240, 180]],
                ['in' => '13:00', 'out' => '17:00', 'window' => [-120, 300]],
            ],
            'required' => 480,
            'flex' => 0,
            'remote' => false,
            'trust' => false,
            'color' => fake()->numberBetween(1, 8),
            'origin_id' => null,
        ];
    }

    public function off(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Off '.fake()->unique()->lexify('????'),
            'slots' => [],
            'required' => 0,
            'flex' => 0,
            'remote' => false,
        ]);
    }

    public function remote(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Remote '.fake()->unique()->lexify('????'),
            'slots' => [],
            'flex' => 0,
            'remote' => true,
        ]);
    }

    public function flexible(int $minutes = 180): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Flexi '.fake()->unique()->lexify('????'),
            'slots' => [
                ['in' => '07:00', 'out' => '11:00', 'window' => [-30, 240]],
                ['in' => '12:00', 'out' => '16:00', 'window' => [-60, 360]],
            ],
            'flex' => $minutes,
        ]);
    }

    public function copiedFrom(Shift $origin): static
    {
        return $this->state(fn (array $attributes): array => ['origin_id' => $origin->id]);
    }
}
