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
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'parent_id' => null,
            'kind' => fake()->randomElement(['department', 'division', 'section', 'office']),
            // unique(), matching AgencyFactory's own `code` generator: the
            // constraint is only UNIQUE (agency_id, code), but callers
            // routinely place several factory workgroups under one shared agency
            // (under() below, or an explicit agency_id override), so the
            // generator must not collide within one agency either.
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->company(),
            'head_id' => null,
        ];
    }

    /** Placed under $parent: same agency (the paired parent_id FK requires it), parent_id set. */
    public function under(Workgroup $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $parent->agency_id,
            'parent_id' => $parent->id,
        ]);
    }
}
