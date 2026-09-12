<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    /** @return array<string, mixed> */
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
            'parent_id' => null,
            'starts' => fake()->dateTimeBetween('-5 years', 'now'),
            'ends' => null,
        ];
    }

    public function under(Deployment $placement): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $placement->agency_id,
            'employee_id' => $placement->employee_id,
            'parent_id' => $placement->id,
            'starts' => $placement->starts,
            'ends' => $placement->ends,
        ]);
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends' => null,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends' => function (array $merged): string {
                $starts = CarbonImmutable::parse($merged['starts'])->startOfDay();

                return fake()->dateTimeBetween(
                    $starts->toDateString(),
                    $starts->max(CarbonImmutable::yesterday())->toDateString(),
                )->format('Y-m-d');
            },
        ]);
    }
}
/** @return static */
