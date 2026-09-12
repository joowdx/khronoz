<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Workgroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workgroup>
 */
class WorkgroupFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'parent_id' => null,
            'kind' => fake()->randomElement(['department', 'division', 'section', 'office']),
            // Shared-agency factory workgroups require unique codes.
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->company(),
            'head_id' => null,
        ];
    }

    public function under(Workgroup $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $parent->agency_id,
            'parent_id' => $parent->id,
        ]);
    }
}
