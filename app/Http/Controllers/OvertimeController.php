<?php

namespace App\Http\Controllers;

use App\Enums\OvertimeMode;
use App\Http\Requests\StoreOvertimeRequest;
use App\Http\Requests\UpdateOvertimeRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\OvertimeResource;
use App\Models\Employee;
use App\Models\Overtime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OvertimeController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Overtime::class);

        $employee = ($id = $request->string('employee')->trim()->toString()) === ''
            ? null
            : Employee::find($id);

        $mode = $request->string('mode')->trim()->toString();
        $mode = in_array($mode, array_column(OvertimeMode::cases(), 'value'), true) ? $mode : '';

        $from = $this->day($request->string('from')->trim()->toString());
        $to = $this->day($request->string('to')->trim()->toString());

        $overtimes = Overtime::query()
            ->with('employee')
            ->when($employee !== null, fn (Builder $query) => $query->where('employee_id', $employee->id))
            ->when($mode !== '', fn (Builder $query) => $query->where('mode', $mode))

            ->when($from !== '', fn (Builder $query) => $query->where('date', '>=', $from))
            ->when($to !== '', fn (Builder $query) => $query->where('date', '<=', $to))
            ->orderByDesc('starts')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('overtimes/index', [
            'overtimes' => OvertimeResource::collection($overtimes->getCollection())->resolve(),
            'pagination' => [
                'from' => $overtimes->firstItem(),
                'to' => $overtimes->lastItem(),
                'total' => $overtimes->total(),
                'previous' => $overtimes->previousPageUrl(),
                'next' => $overtimes->nextPageUrl(),
            ],
            'filters' => [
                'employee' => $employee?->id ?? '',
                'mode' => $mode,
                'from' => $from,
                'to' => $to,
            ],
            'employees' => fn () => $this->employees(),
            'modes' => fn () => $this->modes(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Overtime::class);

        return Inertia::render('overtimes/create', [
            'employees' => fn () => $this->employees(),
            'modes' => fn () => $this->modes(),
        ]);
    }

    public function store(StoreOvertimeRequest $request): RedirectResponse
    {
        try {
            DB::transaction(fn () => Overtime::create([
                ...$request->authorised(),
                'user_id' => $request->user()->id,
            ]));
        } catch (QueryException $e) {
            return $this->refused($e) ?? throw $e;
        }

        return to_route('overtimes.index')->with('success', 'Overtime authorised.');
    }

    public function edit(Overtime $overtime): Response
    {
        Gate::authorize('update', $overtime);

        return Inertia::render('overtimes/edit', [
            'overtime' => OvertimeResource::make($overtime)->resolve(),
            'employees' => fn () => $this->employees(),
            'modes' => fn () => $this->modes(),
        ]);
    }

    public function update(UpdateOvertimeRequest $request, Overtime $overtime): RedirectResponse
    {
        try {
            DB::transaction(fn () => $overtime->update($request->authorised()));
        } catch (QueryException $e) {
            return $this->refused($e) ?? throw $e;
        }

        return to_route('overtimes.index')->with('success', 'Overtime updated.');
    }

    public function destroy(Overtime $overtime): RedirectResponse
    {
        Gate::authorize('delete', $overtime);

        try {
            DB::transaction(fn () => $overtime->delete());
        } catch (QueryException $e) {
            return $this->refused($e) ?? throw $e;
        }

        return to_route('overtimes.index')->with('success', 'Authorisation withdrawn.');
    }

    private function refused(QueryException $e): ?RedirectResponse
    {
        return match ($e->getCode()) {
            '23P01' => back()->withInput()->with('error', 'That person is already authorised for overtime over part of those hours.'),
            'P0001' => back()->withInput()->with('error', 'Those hours fall in a locked ledger. Unlock it first.'),
            default => null,
        };
    }

    private function day(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function modes(): array
    {
        return OvertimeMode::choices();
    }

    /** @return array<int, array<string, mixed>> */
    private function employees(): array
    {
        return EmployeeResource::collection(
            Employee::query()->orderBy('last_name')->orderBy('first_name')->get()
        )->resolve();
    }
}
