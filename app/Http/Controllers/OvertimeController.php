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

/**
 * Work authorised beyond the shift (05-calendar.md).
 *
 * `starts`/`ends` are timestamps rather than a date and two clock times,
 * because an authorisation routinely crosses midnight and 22:00–02:00 is one
 * stretch of work. `overtimes_no_overlap` indexes `tsrange(starts, ends)` for
 * exactly that reason, and it is the one rule here no validation can express —
 * so the 23P01 is translated rather than restated.
 */
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
            // Against the generated `date` column, which is `starts::date` —
            // an overnight stretch belongs to the day it began on, which is
            // how a DTR reads it.
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
            if ($e->getCode() !== '23P01') {
                throw $e;
            }

            return back()->withInput()->with('error', 'That person is already authorised for overtime over part of those hours.');
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
            if ($e->getCode() !== '23P01') {
                throw $e;
            }

            return back()->withInput()->with('error', 'That person is already authorised for overtime over part of those hours.');
        }

        return to_route('overtimes.index')->with('success', 'Overtime updated.');
    }

    public function destroy(Overtime $overtime): RedirectResponse
    {
        Gate::authorize('delete', $overtime);

        $overtime->delete();

        return to_route('overtimes.index')->with('success', 'Authorisation withdrawn.');
    }

    private function day(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * How authorised hours may be compensated. **Two values, not three** —
     * `overtimes_mode_valid` allows `pay` and `cto` and nothing else, and
     * `EnumCheckContractTest` holds the enum to that CHECK. Shipping the
     * cases rather than restating them in TypeScript is what keeps the picker
     * from offering a third the database refuses.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function modes(): array
    {
        return array_map(
            fn (OvertimeMode $mode) => ['value' => $mode->value, 'label' => $mode->label()],
            OvertimeMode::cases(),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function employees(): array
    {
        return EmployeeResource::collection(
            Employee::query()->orderBy('last_name')->orderBy('first_name')->get()
        )->resolve();
    }
}
