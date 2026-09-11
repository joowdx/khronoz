<?php

namespace App\Http\Controllers;

use App\Enums\WorkdayStatus;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\WorkdayResource;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Workday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the engine says happened, across the agency, this month.
 *
 * The filter the screen exists for is **attention**: a day whose status is
 * absent, or that still has a punch with no `actual_at`. Pickers sit behind
 * closures so a partial reload of the list does not re-query them.
 *
 * `employee` is loaded `withTrashed()`. That is deliberately unlike every
 * other screen in the application — nothing else uses `withTrashed()` —
 * because a workday is a line of a DTR, and a DTR is a historical pay
 * record, not a live roster. `RemoveEmployee` closes the open placement
 * and soft-deletes the person, and their final month is exactly the ledger
 * that still has to be locked and signed. The index join does not apply
 * the soft-delete scope, so the row is listed either way; without
 * `withTrashed()` it would render nameless.
 */
class WorkdayController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Ledger::class);

        $month = $this->month($request);

        $employee = ($id = $request->string('employee')->trim()->toString()) === ''
            ? null
            : Employee::find($id);

        $status = WorkdayStatus::tryFrom($request->string('status')->trim()->toString());
        $attention = $request->boolean('attention');

        $workdays = Workday::query()
            ->select('workdays.*')
            ->join('employees', 'employees.id', '=', 'workdays.employee_id')
            ->with([
                'employee' => fn (BelongsTo $employee) => $employee
                    ->withTrashed()
                    ->with('currentDeployment.workgroup'),
                'punches' => fn (HasMany $punches) => $punches->orderBy('slot')->orderBy('expected_at'),
            ])
            ->where('workdays.month', $month->toDateString())
            ->when($employee !== null, fn (Builder $query) => $query->where('workdays.employee_id', $employee->id))
            ->when($status !== null, fn (Builder $query) => $query->where('workdays.status', $status))
            ->when($attention, function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('workdays.status', WorkdayStatus::Absent)
                        ->orWhereHas('punches', fn (Builder $query) => $query->whereNull('actual_at'));
                });
            })
            ->orderBy('workdays.date')
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name')
            ->orderBy('workdays.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('workdays/index', [
            'workdays' => WorkdayResource::collection($workdays->getCollection())->resolve(),
            'pagination' => [
                'from' => $workdays->firstItem(),
                'to' => $workdays->lastItem(),
                'total' => $workdays->total(),
                'previous' => $workdays->previousPageUrl(),
                'next' => $workdays->nextPageUrl(),
            ],
            'filters' => [
                'month' => $month->format('Y-m'),
                'employee' => $employee?->id ?? '',
                'status' => $status?->value ?? '',
                'attention' => $attention,
            ],
            'employees' => fn () => $this->employees(),
            'statuses' => fn () => WorkdayStatus::choices(),
        ]);
    }

    /**
     * `YYYY-MM`; defaults to the current month. A malformed value falls back
     * to the default rather than filtering by garbage.
     */
    private function month(Request $request): CarbonImmutable
    {
        $value = $request->string('month')->trim()->toString();

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return now()->toImmutable()->startOfMonth();
        }

        return CarbonImmutable::parse($value.'-01')->startOfMonth();
    }

    /** @return array<int, array<string, mixed>> */
    private function employees(): array
    {
        return EmployeeResource::collection(
            Employee::query()->orderBy('last_name')->orderBy('first_name')->get()
        )->resolve();
    }
}
