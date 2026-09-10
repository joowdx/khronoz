<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * employee_id and workgroup_id are callbacks, not Employee::factory() /
     * Workgroup::factory() directly: the paired FKs (employee_id, agency_id) and
     * (workgroup_id, agency_id) require the employee and workgroup to belong to the
     * same agency as this deployment, so each is created explicitly under
     * the agency_id resolved just above rather than getting its own random
     * agency. agency_id is declared first so both callbacks can read the
     * already-resolved value from $attributes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'workgroup_id' => fn (array $attributes) => Workgroup::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'starts' => fake()->dateTimeBetween('-5 years', 'now'),
            'ends' => null,
        ];
    }

    /** Currently active: no end date. The default already, named for readability at the call site. */
    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends' => null,
        ]);
    }

    /** Ended on or after it started (deployments_dates_ordered). */
    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends' => fake()->dateTimeBetween($attributes['starts'], 'now'),
        ]);
    }
}
