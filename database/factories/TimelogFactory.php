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
    /**
     * Define the model's default state: a device punch carried in by an import
     * run, on a terminal nobody is enrolled on — so it lands **unresolved**,
     * which is the shape the resolution tests build up from.
     *
     * `employee_id` and `enrollment_id` are set to null here and that is not
     * the factory declining to fill them: the application may never write
     * those columns, and `timelogs_resolve` overwrites whatever arrives. They
     * appear only because Model::shouldBeStrict() throws on an attribute no
     * value was ever set for (.ai/rules/factories.md).
     *
     * The consequence matters at every call site: `create()` does not
     * re-select, so the returned model reports the *sent* nulls even when the
     * database resolved the row. Read resolution through `fresh()`.
     *
     * `time` is a fixed literal rather than a random one, because it is the
     * third column of the attlog natural key and the column resolution keys
     * off — a random value makes both dedupe and coverage a coin toss.
     *
     * @return array<string, mixed>
     */
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

    /**
     * A punch that will resolve: same terminal and uid as $enrollment, dated
     * inside its range.
     *
     * It does **not** set `employee_id` — the trigger does. That is the whole
     * point of the state, and a factory that set it would be asserting the
     * thing under test.
     */
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

    /** Captured on $terminal, whose agency the row must share. */
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

    /**
     * Entered by hand because the device missed it. No sync — the CHECKs tie
     * `source` to `sync_id` in both directions — and a user, which MC 21
     * s. 1991 requires.
     */
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

    /**
     * Marked bad. All three columns together: `timelogs_void_needs_reason`
     * refuses an unexplained void and `timelogs_void_pairs_actor` refuses an
     * unattributable one, so a state that set only `voided_at` would be
     * refused by the database rather than merely incomplete.
     *
     * The voider defaults to a user of this row's own agency, resolved after
     * the states merge for the reason DeploymentFactory's parents are
     * closures.
     */
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
