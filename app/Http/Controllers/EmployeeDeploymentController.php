<?php

namespace App\Http\Controllers;

use App\Actions\ReassignEmployee;
use App\Actions\TransferEmployee;
use App\Http\Requests\DeployEmployeeRequest;
use App\Http\Requests\EndEmployeeDeploymentRequest;
use App\Jobs\FanOutRecompute;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EmployeeDeploymentController extends Controller
{
    public function store(
        DeployEmployeeRequest $request,
        Employee $employee,
        TransferEmployee $transfer,
        ReassignEmployee $reassign,
    ): RedirectResponse {
        $workgroup = Workgroup::findOrFail($request->validated('workgroup_id'));
        $reassigning = $request->boolean('reassignment');

        try {
            $reassigning
                ? $reassign->handle($employee, $workgroup, $request->date('starts'), $request->date('ends'))
                : $transfer->handle($employee, $workgroup, $request->date('starts'), $request->date('ends'));
        } catch (QueryException $e) {

            throw match ($e->getCode()) {
                '23P01' => ValidationException::withMessages(['starts' => [
                    $reassigning ? 'Overlaps an existing reassignment.' : 'Overlaps an existing deployment.',
                ]]),
                '23514' => ValidationException::withMessages(['starts' => ['Before the current placement began.']]),
                'P0001' => ValidationException::withMessages(['starts' => [
                    $this->frozen($e)
                        ? 'That range covers a locked month. Unlock the ledger first.'
                        : ($reassigning
                            ? 'Outside the placement this reassignment departs from.'
                            : 'End the open reassignment before transferring this employee.'),
                ]]),
                default => $e,
            };
        }

        $this->recompute($employee, $request->date('starts'), $request->date('ends'));

        $verb = $reassigning ? 'reassigned to' : 'moved to';

        return redirect()->route('employees.show', $employee)->with('success', "{$employee->name} {$verb} {$workgroup->name}.");
    }

    public function update(EndEmployeeDeploymentRequest $request, Employee $employee): RedirectResponse
    {
        try {
            $closed = DB::transaction(fn () => $employee->deployments()
                ->whereKey($request->validated('deployment'))
                ->whereRaw('ends IS NOT DISTINCT FROM ?', [$request->date('expects')?->toDateString()])
                ->update(['ends' => $request->date('ends')]));
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                '23514' => ValidationException::withMessages(['ends' => ['Before the current placement began.']]),
                'P0001' => ValidationException::withMessages(['ends' => [
                    $this->frozen($e)
                        ? 'That placement covers a locked month. Unlock the ledger first.'
                        : 'End the reassignment nested under this placement first.',
                ]]),
                default => $e,
            };
        }

        if ($closed === 0) {
            return redirect()->route('employees.show', $employee)
                ->with('error', 'That placement changed while you were looking at it. Check the history and try again.');
        }

        $expects = $request->date('expects');
        $ends = $request->date('ends');

        $this->recompute($employee, $expects !== null && $expects->lt($ends) ? $expects : $ends, null);

        return redirect()->route('employees.show', $employee)->with('success', 'Placement ended.');
    }

    public function destroy(Employee $employee, Deployment $deployment): RedirectResponse
    {
        Gate::authorize('update', $employee);

        try {

            DB::transaction(fn () => $deployment->delete());
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                '23001' => ValidationException::withMessages([
                    'deployment' => ['Remove the reassignment nested under this placement first.'],
                ]),
                'P0001' => ValidationException::withMessages([
                    'deployment' => [
                        $this->frozen($e)
                            ? 'That placement covers a locked month. Unlock the ledger first.'
                            : 'Remove the reassignment nested under this placement first.',
                    ],
                ]),
                default => $e,
            };
        }

        $this->recompute($employee, $deployment->starts, $deployment->ends);

        return redirect()->route('employees.show', $employee)->with('success', 'Deployment removed.');
    }

    private function frozen(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'locked months');
    }

    private function recompute(Employee $employee, CarbonInterface $from, ?CarbonInterface $to): void
    {
        FanOutRecompute::forEmployees([$employee->id], $from->toDateString(), $to?->toDateString());
    }
}
