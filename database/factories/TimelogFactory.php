<?php

namespace Database\Factories;

use App\Enums\TimelogSource;
use App\Models\Agency;
use App\Models\Enrollment;
use App\Models\Sync;
use App\Models\Terminal;
use App\Models\Timelog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Timelog>
 */
class TimelogFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'terminal_id' => fn (array $attributes) => Terminal::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'sync_id' => fn (array $attributes) => Sync::factory()->create([
                'agency_id' => $attributes['agency_id'],
                'terminal_id' => $attributes['terminal_id'],
            ])->id,
            'employee_id' => null,
            'enrollment_id' => null,
            'uid' => fn () => str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'time' => '2026-09-01 08:01:23',
            'state' => 0,
            'mode' => 1,
            'source' => TimelogSource::Device,
            'user_id' => null,
            'voided_at' => null,
            'reason' => null,
            'voided_by' => null,
        ];
    }

    public function resolving(Enrollment $enrollment): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $enrollment->agency_id,
            'terminal_id' => $enrollment->terminal_id,
            'uid' => $enrollment->uid,
            'sync_id' => fn (array $merged) => Sync::factory()->create([
                'agency_id' => $enrollment->agency_id,
                'terminal_id' => $enrollment->terminal_id,
            ])->id,
            'time' => $enrollment->starts->copy()->addDays(7)->setTime(8, 1, 23)->toDateTimeString(),
        ]);
    }

    public function on(Terminal $terminal): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $terminal->agency_id,
            'terminal_id' => $terminal->id,
            'sync_id' => fn (array $merged) => Sync::factory()->create([
                'agency_id' => $terminal->agency_id,
                'terminal_id' => $terminal->id,
            ])->id,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => TimelogSource::Manual,
            'sync_id' => null,
            'user_id' => fn (array $merged) => User::factory()->create([
                'agency_id' => $merged['agency_id'],
            ])->id,
        ]);
    }

    public function voided(string $reason = 'Duplicate scan', ?User $by = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'voided_at' => now(),
            'reason' => $reason,
            'voided_by' => $by?->id ?? fn (array $merged) => User::factory()->create([
                'agency_id' => $merged['agency_id'],
            ])->id,
        ]);
    }
}
