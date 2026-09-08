<?php

namespace Database\Factories;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->company(),
            'platform' => false,
            // (object) [], not []: the `array` cast round-trips either PHP value
            // to the same empty PHP array, but json_encode([]) produces the JSON
            // array literal `[]`, which fails agencies_settings_object. Casting
            // to stdClass is the one PHP value that always encodes to `{}`.
            'settings' => (object) [],
        ];
    }

    /** The hidden row that owns the shared data; see Agency::platform(). */
    public function platform(): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => 'platform',
            'name' => 'khronoz',
            'platform' => true,
        ]);
    }
}
