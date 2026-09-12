<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Employee;
use App\Models\Ledger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ledger>
 */
class LedgerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'month' => now()->startOfMonth(),
            'locked_at' => null,
        ];
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'locked_at' => now(),
        ]);
    }
}
