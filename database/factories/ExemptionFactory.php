<?php

namespace Database\Factories;

use App\Enums\ExemptionType;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exemption>
 */
class ExemptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'date' => '2026-09-15',
            'until' => fn (array $merged): string => $merged['date'],
            'type' => ExemptionType::Leave,
            'starts' => null,
            'ends' => null,
            'reference' => 'Application No. '.fake()->numberBetween(100, 999),
            'remarks' => null,
            'user_id' => fn (array $attributes) => User::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'approved_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['date'])->subDays(7),
        ];
    }

    public function spanning(int $days): static
    {
        return $this->state(fn (array $attributes): array => [
            'until' => fn (array $merged): string => CarbonImmutable::parse($merged['date'])
                ->addDays($days - 1)
                ->toDateString(),
        ]);
    }

    public function hours(string $starts = '10:00:00', string $ends = '12:00:00'): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts' => $starts,
            'ends' => $ends,
        ]);
    }

    public function personal(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ExemptionType::Personal,
        ]);
    }
}
