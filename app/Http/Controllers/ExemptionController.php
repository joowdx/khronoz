<?php

namespace App\Http\Controllers;

use App\Enums\ExemptionType;
use App\Http\Requests\StoreExemptionRequest;
use App\Http\Requests\UpdateExemptionRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\ExemptionResource;
use App\Models\Employee;
use App\Models\Exemption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Why somebody was not at their desk and it is excused (05-calendar.md).
 *
 * A top-level list rather than a section on each employee's profile, because
 * the work is done in batches: a timekeeper closing a payroll period asks "who
 * was on leave this fortnight", not "show me Amihan". The employee filter
 * serves the other question without a second screen.
 *
 * `date .. until` is **inclusive on both sides** and `until` is NOT NULL
 * (decision 38) — a one-day exemption is `until = date`, and RA 11210's 105
 * continuous days is one row, not 105.
 */
class ExemptionController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Exemption::class);

        $employee = ($id = $request->string('employee')->trim()->toString()) === ''
            ? null
            : Employee::find($id);

        $type = $request->string('type')->trim()->toString();
        $type = in_array($type, array_column(ExemptionType::cases(), 'value'), true) ? $type : '';

        $from = $this->day($request->string('from')->trim()->toString());
        $to = $this->day($request->string('to')->trim()->toString());

        $exemptions = Exemption::query()
            ->with('employee')
            ->when($employee !== null, fn (Builder $query) => $query->where('employee_id', $employee->id))
            ->when($type !== '', fn (Builder $query) => $query->where('type', $type))
            // Overlap, not containment: a 105-day leave that merely *crosses*
            // the fortnight being closed is the row a timekeeper most needs to
            // see, and a `whereBetween('date', …)` would miss it entirely.
            ->when($from !== '', fn (Builder $query) => $query->where('until', '>=', $from))
            ->when($to !== '', fn (Builder $query) => $query->where('date', '<=', $to))
            ->orderByDesc('date')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('exemptions/index', [
            'exemptions' => ExemptionResource::collection($exemptions->getCollection())->resolve(),
            'pagination' => [
                'from' => $exemptions->firstItem(),
                'to' => $exemptions->lastItem(),
                'total' => $exemptions->total(),
                'previous' => $exemptions->previousPageUrl(),
                'next' => $exemptions->nextPageUrl(),
            ],
            'filters' => [
                'employee' => $employee?->id ?? '',
                'type' => $type,
                'from' => $from,
                'to' => $to,
            ],
            'employees' => fn () => $this->employees(),
            'types' => fn () => $this->types(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Exemption::class);

        return Inertia::render('exemptions/create', [
            'employees' => fn () => $this->employees(),
            'types' => fn () => $this->types(),
        ]);
    }

    public function store(StoreExemptionRequest $request): RedirectResponse
    {
        Exemption::create([
            ...$request->validated(),
            // Who entered it. Never sent by the client — decision 39's
            // actor_of_agency trigger refuses a user of a third agency.
            'user_id' => $request->user()->id,
        ]);

        return to_route('exemptions.index')->with('success', 'Exemption recorded.');
    }

    public function edit(Exemption $exemption): Response
    {
        Gate::authorize('update', $exemption);

        return Inertia::render('exemptions/edit', [
            'exemption' => ExemptionResource::make($exemption)->resolve(),
            'employees' => fn () => $this->employees(),
            'types' => fn () => $this->types(),
        ]);
    }

    public function update(UpdateExemptionRequest $request, Exemption $exemption): RedirectResponse
    {
        $exemption->update($request->validated());

        return to_route('exemptions.index')->with('success', 'Exemption updated.');
    }

    public function destroy(Exemption $exemption): RedirectResponse
    {
        Gate::authorize('delete', $exemption);

        $exemption->delete();

        return to_route('exemptions.index')->with('success', 'Exemption removed.');
    }

    private function day(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * The kinds an exemption may be, in the order the enum declares them.
     *
     * Shipped rather than restated in TypeScript, matching
     * `UserController::accesses()`: `EnumCheckContractTest` holds these cases
     * to the database's CHECK, and a second list in the front end is a copy
     * nothing holds to either.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function types(): array
    {
        return array_map(
            fn (ExemptionType $type) => ['value' => $type->value, 'label' => $type->label()],
            ExemptionType::cases(),
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
