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
    /**
     * Define the model's default state: three hours after an ordinary 8–5 day.
     *
     * **`date` is deliberately not set**, which is the one departure from
     * .ai/rules/factories.md's "set every real column explicitly". It is a
     * generated column, and Postgres refuses any value supplied for one
     * (428C9) — so the rule's reason, that a column left out is a column
     * nobody controls, does not apply: `starts::date` controls it absolutely.
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

    /**
     * Read the generated `date` back after the insert.
     *
     * Without this, `Overtime::factory()->create()->date` raises
     * MissingAttributeException under Model::shouldBeStrict(): create() never
     * re-selects the row, so a column the INSERT did not supply is absent
     * rather than null. Throwing is the right default — it is how a real
     * missing column is caught — so the fix belongs here rather than in a
     * relaxed model.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Overtime $overtime) => $overtime->refresh());
    }

    /**
     * Crossing midnight, which is the whole reason `starts` and `ends` are
     * timestamps: one row, and `date` is the day it **began**.
     *
     * Literal on both sides rather than derived from `starts`. A state
     * attribute cannot read its **own** key through a closure — the factory
     * expands attributes in order and hands each closure the definition as it
     * stands, so a `starts` closure reading `$merged['starts']` receives the
     * unresolved Closure. A caller wanting other dates passes both.
     */
    public function overnight(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts' => '2026-09-15 22:00:00',
            'ends' => '2026-09-16 02:00:00',
        ]);
    }

    /** Earned as compensatory credit rather than paid. */
    public function cto(): static
    {
        return $this->state(fn (array $attributes): array => [
            'mode' => OvertimeMode::Cto,
        ]);
    }
}
