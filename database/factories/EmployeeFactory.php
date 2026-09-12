<?php

namespace Database\Factories;

use App\Enums\Sex;
use App\Models\Agency;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            // The number remains unique across soft-deleted factory records.
            'number' => fake()->unique()->numerify('EMP#####'),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->lastName(),
            'last_name' => fake()->lastName(),
            'suffix' => null,
            'sex' => fake()->randomElement(Sex::cases()),
            'birthdate' => fake()->dateTimeBetween('-60 years', '-21 years'),
            'email' => fake()->safeEmail(),
            'mobile' => fake()->numerify('09#########'),
            'position' => fake()->jobTitle(),
            // An array encodes as the JSON array required for tags.
            'tags' => [],
            'exempt' => false,
        ];
    }

    public function exempt(): static
    {
        return $this->state(fn (array $attributes): array => [
            'exempt' => true,
        ]);
    }

    public function tagged(string ...$tags): static
    {
        return $this->state(fn (array $attributes): array => [
            'tags' => $tags,
        ]);
    }
}
