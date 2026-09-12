<?php

namespace App\Actions;

use App\Attendance\LedgerPolicyResolver;
use App\Attendance\LedgerSnapshot;
use App\Enums\Permission;
use App\Enums\Work;
use App\Models\Cadence;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class LockLedger
{
    public function __construct(private LedgerPolicyResolver $policies, private LedgerSnapshot $snapshots) {}

    public function handle(Employee $employee, CarbonInterface|string $starts, CarbonInterface|string $ends, Work $scope, User $actor, ?Cadence $cadence = null): Ledger
    {
        Gate::forUser($actor)->authorize(Permission::ManageLedgers->value);

        return DB::transaction(function () use ($employee, $starts, $ends, $scope, $actor, $cadence): Ledger {
            $employee = Employee::withTrashed()->lockForUpdate()->findOrFail($employee->id);
            $from = CarbonImmutable::parse($starts)->startOfDay();
            $to = CarbonImmutable::parse($ends)->startOfDay();
            if ($to->lt($from) || $to->gt(today())) {
                throw ValidationException::withMessages(['ends' => 'The ledger must end on or before today and after its start.']);
            }
            $assigned = $employee->cadence ?? Cadence::query()->where('agency_id', $employee->agency_id)->where('preferred', true)->whereNull('retired_at')->first();
            $cadence ??= $assigned;
            if ($cadence !== null && ($cadence->agency_id !== $employee->agency_id || (! Gate::forUser($actor)->allows(Permission::ManageLedgers->value) && $cadence->id !== $assigned?->id))) {
                throw ValidationException::withMessages(['cadence_id' => 'Select an eligible employee or agency cadence.']);
            }
            [$expectedFrom, $expectedTo] = $cadence?->bounds($from) ?? [$from->startOfMonth(), $from->endOfMonth()->startOfDay()];
            if (! $from->isSameDay($expectedFrom) || ! $to->isSameDay($expectedTo)) {
                throw ValidationException::withMessages(['starts' => 'The date range must match the selected cadence.']);
            }
            $revisions = Ledger::query()->where('employee_id', $employee->id)->whereDate('starts', $from)->whereDate('ends', $to)->where('scope', $scope);
            if ((clone $revisions)->whereNull('unlocked_at')->exists()) {
                throw ValidationException::withMessages(['ledger' => 'This ledger range is already locked.']);
            }
            $ledger = new Ledger(['agency_id' => $employee->agency_id, 'employee_id' => $employee->id, 'cadence_id' => $cadence?->id, 'starts' => $from, 'ends' => $to, 'scope' => $scope, 'revision' => ((clone $revisions)->max('revision') ?? 0) + 1, 'locked_at' => now(), 'locked_by' => $actor->id]);
            if ($ledger->workdays()->whereHas('punches', fn ($query) => $query->where('kind', 'out')->whereNull('actual_at')->where('expected_at', '>', now()))->exists()) {
                throw ValidationException::withMessages(['ledger' => 'A workday still has a pending out punch.']);
            }
            $policy = $this->policies->resolve($employee, $to);
            $signers = $this->policies->signers($employee, $to, $policy);
            foreach ($signers as $signer) {
                if ($signer['user_ids'] === []) {
                    throw ValidationException::withMessages(['policy' => 'No eligible user resolves the required '.$signer['role'].' role.']);
                }
            }
            $ledger->fill([...$this->snapshots->capture($ledger), 'policy' => $policy, 'signers' => $signers]);
            $ledger->save();

            return $ledger;
        });
    }
}
