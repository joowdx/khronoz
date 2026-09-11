<?php

namespace Database\Factories;

use App\Enums\MissingSide;
use App\Enums\WorkdayStatus;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Shift;
use App\Models\Workday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workday>
 */
class WorkdayFactory extends Factory
{
    /**
     * Define the model's default state: a present ordinary day in September
     * 2026, against a Standard shift snapshot.
     *
     * **`month` is deliberately not set**, which is the one departure from
     * .ai/rules/factories.md's "set every real column explicitly". It is a
     * generated column, and Postgres refuses any value supplied for one
     * (428C9) — so the rule's reason, that a column left out is a column
     * nobody controls, does not apply: `date`'s month controls it absolutely.
     *
     * The ledger's `month` is derived from `date` rather than faked
     * independently: the three-column FK refuses a workday whose generated
     * month is not that ledger's.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'date' => '2026-09-15',
            'employee_id' => fn (array $attributes) => Employee::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'ledger_id' => fn (array $attributes) => Ledger::factory()->create([
                'agency_id' => $attributes['agency_id'],
                'employee_id' => $attributes['employee_id'],
                'month' => CarbonImmutable::parse($attributes['date'])->startOfMonth()->toDateString(),
            ])->id,
            'shift_id' => fn (array $attributes) => Shift::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            // Snapshot::of() (decision 69). A flat {name, slots, …} here is a
            // test that proves nothing — WorkdayResource read shift['name'],
            // the assertion passed, and the Shift column was empty on every
            // real row. Keys are held to the orchestrator by WorkdayTest.
            'shift' => fn (array $attributes) => [
                'shift' => [
                    'id' => $attributes['shift_id'],
                    'name' => 'Standard',
                    'slots' => [
                        ['in' => '08:00', 'out' => '12:00', 'window' => [-240, 180]],
                        ['in' => '13:00', 'out' => '17:00', 'window' => [-120, 300]],
                    ],
                    'required' => 480,
                    'flex' => 0,
                    'remote' => false,
                    'trust' => false,
                ],
                'settings' => [
                    'night_from' => '18:00',
                    'premium_hours' => false,
                    'suspension_charge' => true,
                    'missing_side' => MissingSide::Void->value,
                ],
                'holidays' => [],
                'suspensions' => [],
            ],
            'exemption_id' => null,
            'status' => WorkdayStatus::Present,
            'premium' => null,
            'worked' => 0,
            'credited' => 0,
            'tardy' => 0,
            'undertime' => 0,
            'excess' => 0,
            'night' => 0,
            'night_excess' => 0,
            'computed_at' => '2026-09-15 18:00:00',
        ];
    }

    /**
     * Read the generated `month` back after the insert.
     *
     * Without this, `Workday::factory()->create()->month` raises
     * MissingAttributeException under Model::shouldBeStrict(): create() never
     * re-selects the row, so a column the INSERT did not supply is absent
     * rather than null.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Workday $workday) => $workday->refresh());
    }
}
