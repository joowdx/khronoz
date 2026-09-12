<?php

namespace Database\Factories;

use App\Enums\PunchKind;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Punch;
use App\Models\Timelog;
use App\Models\Workday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Punch>
 */
class PunchFactory extends Factory
{
    /**
     * Define the model's default state: slot 1 in, filled by a resolved
     * timelog of the same employee as the workday.
     *
     * The chain is built from one employee. A punch's `employee_id` must
     * match the workday's (composite FK) and the timelog's (the other
     * composite FK) — two `fake()` employees would disagree and be refused.
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
            'workday_id' => fn (array $attributes) => Workday::factory()->create([
                'agency_id' => $attributes['agency_id'],
                'employee_id' => $attributes['employee_id'],
            ])->id,
            'slot' => 1,
            'kind' => PunchKind::In,
            'expected_at' => '2026-09-15 08:00:00',
            'timelog_id' => function (array $attributes): string {
                $enrollment = Enrollment::factory()->create([
                    'agency_id' => $attributes['agency_id'],
                    'employee_id' => $attributes['employee_id'],
                ]);

                return Timelog::factory()->resolving($enrollment)->create()->id;
            },
            'actual_at' => '2026-09-15 08:01:00',
            'deviation' => 1,
        ];
    }

    public function missed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'timelog_id' => null,
            'actual_at' => null,
            'deviation' => null,
        ]);
    }
}
