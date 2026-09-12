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
            // unique(), not just numerify(): UNIQUE (agency_id, number) is not
            // partial (R7), so a soft-deleted employee keeps their number
            // reserved forever and re-inserting it is 23505. The number must
            // be unique across the whole test run, not merely among live rows.
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
            // Plain [], not (object) []: the `array` cast round-trips either
            // PHP value to the same empty PHP array, but json_encode((object) [])
            // produces `{}`, which employees_tags_valid rejects — it requires
            // a jsonb array, not an object. Mirror image of AgencyFactory's
            // `settings` default, which needs `{}` and so uses (object) [].
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
