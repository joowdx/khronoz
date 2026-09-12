<?php

namespace Database\Factories;

use App\Enums\Work;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ledger> */
class LedgerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create(['agency_id' => $attributes['agency_id']])->id,
            'cadence_id' => null,
            'starts' => '2026-08-01',
            'ends' => '2026-08-31',
            'scope' => Work::All,
            'revision' => 1,
            'locked_at' => now(),
            'locked_by' => fn (array $attributes) => User::query()->where('agency_id', $attributes['agency_id'])->where('employee_id', $attributes['employee_id'])->value('id') ?? User::factory()->create(['agency_id' => $attributes['agency_id'], 'employee_id' => $attributes['employee_id']])->id,
            'unlocked_at' => null,
            'unlocked_by' => null,
            'calculation' => [
                'workdays' => [],
                'totals' => ['worked' => 0, 'credited' => 0, 'tardy' => 0, 'undertime' => 0, 'excess' => 0, 'night' => 0, 'nightExcess' => 0, 'overtime' => 0, 'tardyOccurrences' => 0, 'undertimeOccurrences' => 0, 'absences' => 0],
                'settings' => ['night_from' => '18:00', 'overtime_after_weekly_minutes' => null, 'occurrences' => true, 'suspension_charge' => true, 'premium_hours' => false, 'overtime_gates' => true, 'missing_side' => 'void'],
            ],
            'identity' => function (array $attributes): array {
                $employee = Employee::withoutGlobalScopes()->findOrFail($attributes['employee_id']);
                $agency = Agency::withoutGlobalScopes()->findOrFail($attributes['agency_id']);

                return [
                    'agency' => ['id' => $agency->id, 'name' => $agency->name, 'code' => $agency->code],
                    'employee' => ['id' => $employee->id, 'name' => $employee->name, 'number' => $employee->number, 'position' => $employee->position],
                    'workgroup' => null,
                    'cadence' => ['id' => null, 'name' => 'Monthly', 'kind' => 'monthly', 'rules' => ['starts' => [1]], 'anchor' => null],
                ];
            },
            'policy' => ['template' => 'form48', 'roles' => ['employee'], 'supervisor' => 'operative', 'head_kind' => null],
            'signers' => function (array $attributes): array {
                $user = User::findOrFail($attributes['locked_by']);

                return [['role' => 'employee', 'user_ids' => [$user->id], 'users' => [[
                    'id' => $user->id,
                    'name' => $user->name,
                    'position' => Employee::withoutGlobalScopes()->find($user->employee_id)?->position,
                ]]]];
            },
        ];
    }

    public function locked(): static
    {
        return $this;
    }
}
