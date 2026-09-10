<?php

namespace App\Http\Controllers;

use App\Actions\MoveEmployee;
use App\Http\Requests\EndEmployeeDeploymentRequest;
use App\Http\Requests\MoveEmployeeRequest;
use App\Models\Employee;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Move to a new placement or end the open one; deployment history is read on the employee profile. */
class EmployeeDeploymentController extends Controller
{
    /**
     * unit_id names a real row of this tenant (MoveEmployeeRequest's exists
     * rule), so Unit::findOrFail is safe: AgencyScope already limits it to
     * the current tenant, and the request already proved the id exists
     * there before this method runs.
     */
    public function store(MoveEmployeeRequest $request, Employee $employee, MoveEmployee $move): RedirectResponse
    {
        $unit = Unit::findOrFail($request->validated('unit_id'));

        try {
            $move->handle($employee, $unit, $request->date('starts'));
        } catch (QueryException $e) {
            /**
             * The database decides both of these, and this catch only
             * translates the refusal it already made — MoveEmployee runs no
             * pre-check (R16) precisely so a concurrent request can never
             * slip between a check and the insert. Do not "simplify" either
             * branch into a query that looks for the conflict before
             * writing: that would reintroduce the second source of truth R16
             * forbids, and it still wouldn't be safe under concurrency.
             *
             * | SQLSTATE | Constraint                | The move that reaches it |
             * | -------- | ------------------------- | ------------------------ |
             * | 23P01    | deployments_no_overlap    | the new range covers a closed, historical deployment — or, single-threaded impossible but the reason this branch exists, a second concurrent move that opened its own row between this one's close and insert |
             * | 23514    | deployments_dates_ordered | `starts` is on or before the open deployment's own `starts`, so closing it at `starts - 1` would leave `ends < starts` |
             *
             * MEASURED: with an open deployment present, no `starts` value
             * can reach 23P01 single-threaded — the exclusion constraint
             * keeps every range disjoint, so the open row always holds the
             * maximum start and a colliding date trips 23514 first. 23P01 is
             * the concurrency case and the closed-history case; 23514 is the
             * one a clerk backdating a move actually hits.
             *
             * Anything that is neither is a real failure, not a normal user
             * mistake, so it re-throws untouched.
             */
            throw match ($e->getCode()) {
                '23P01' => ValidationException::withMessages(['starts' => ['Overlaps an existing deployment.']]),
                '23514' => ValidationException::withMessages(['starts' => ['Before the current placement began.']]),
                default => $e,
            };
        }

        return redirect()->route('employees.show', $employee)->with('success', "{$employee->name} moved to {$unit->name}.");
    }

    /**
     * End the placement on its last day, inclusive. Unlike a move, this
     * opens no replacement row, so ends is the supplied day, not day - 1.
     *
     * The open-row guard is part of the UPDATE, never a model read followed
     * by save(): re-dating a closed deployment violates no constraint, so
     * the application is the only guard against a stale close rewriting
     * history. Zero affected rows means there was nothing left to end.
     * Eloquent supplies updated_at on this query-builder update.
     *
     * | SQLSTATE | Constraint | Reached by |
     * | --- | --- | --- |
     * | 23514 | deployments_dates_ordered | a placement that starts after ends; the request checks first, but a concurrent move can change the open placement before this write |
     *
     * The transaction makes a caught refusal recoverable even inside another
     * transaction. Other failures propagate unchanged.
     */
    public function update(EndEmployeeDeploymentRequest $request, Employee $employee): RedirectResponse
    {
        try {
            $closed = DB::transaction(fn () => $employee->deployments()->whereNull('ends')
                ->update(['ends' => $request->date('ends')]));
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                '23514' => ValidationException::withMessages(['ends' => ['Before the current placement began.']]),
                default => $e,
            };
        }

        if ($closed === 0) {
            return redirect()->route('employees.show', $employee)->with('error', 'No open placement to end.');
        }

        return redirect()->route('employees.show', $employee)->with('success', 'Placement ended.');
    }
}
