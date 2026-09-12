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
    /**
     * employee_id is a callback, not Employee::factory() directly: the paired
     * FK (employee_id, agency_id) requires the employee to belong to the same
     * agency as this ledger, so it is created under the agency_id resolved
     * just above. agency_id is declared first so the callback can read it.
     *
     * `month` is the first of a month — ledgers_month_is_first_of_month
     * refuses any other day. `now()->startOfMonth()`, never a raw
     * fake()->date(), which lands mid-month more often than not.
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
            'month' => now()->startOfMonth(),
            'locked_at' => null,
        ];
    }

    /** The month is frozen against recomputation. */
    public function locked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'locked_at' => now(),
        ]);
    }
}
