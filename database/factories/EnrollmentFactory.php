<?php

namespace Database\Factories;

use App\Enums\EnrollmentPrivilege;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Terminal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'terminal_id' => fn (array $attributes) => Terminal::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'uid' => fn () => str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'privilege' => EnrollmentPrivilege::User,
            'starts' => '2026-01-01',
            'ends' => null,
        ];
    }

    public function on(Terminal $terminal): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $terminal->agency_id,
            'terminal_id' => $terminal->id,
        ]);
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
        ]);
    }

    public function closed(string $after = '+1 year'): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends' => fn (array $merged): string => CarbonImmutable::parse($merged['starts'])
                ->modify($after)
                ->toDateString(),
        ]);
    }

    public function enroller(): static
    {
        return $this->state(fn (array $attributes): array => [
            'privilege' => EnrollmentPrivilege::Enroller,
        ]);
    }
}
