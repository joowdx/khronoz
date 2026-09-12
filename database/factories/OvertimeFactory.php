<?php

namespace Database\Factories;

use App\Enums\OvertimeMode;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Overtime;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Overtime>
 */
class OvertimeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'starts' => '2026-09-15 17:00:00',
            'ends' => '2026-09-15 20:00:00',
            'purpose' => 'Year-end closing of accounts',
            'mode' => OvertimeMode::Pay,
            'reference' => 'Office Order No. '.fake()->numberBetween(10, 99),
            'user_id' => fn (array $attributes) => User::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Overtime $overtime) => $overtime->refresh());
    }

    public function overnight(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts' => '2026-09-15 22:00:00',
            'ends' => '2026-09-16 02:00:00',
        ]);
    }

    public function cto(): static
    {
        return $this->state(fn (array $attributes): array => [
            'mode' => OvertimeMode::Cto,
        ]);
    }
}
