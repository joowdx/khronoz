<?php

namespace Database\Factories;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->company(),
            'platform' => false,
            // An object encodes as the required JSON object rather than an array.
            'settings' => (object) [],
        ];
    }

    public function platform(): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => 'platform',
            'name' => 'khronoz',
            'platform' => true,
        ]);
    }
}
