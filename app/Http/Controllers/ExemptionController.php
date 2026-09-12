<?php

namespace App\Http\Controllers;

use App\Enums\ExemptionType;
use App\Http\Requests\StoreExemptionRequest;
use App\Http\Requests\UpdateExemptionRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\ExemptionResource;
use App\Jobs\FanOutRecompute;
use App\Models\Employee;
use App\Models\Exemption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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
        try {
            $exemption = DB::transaction(fn () => Exemption::create([
                ...$request->validated(),

                'user_id' => $request->user()->id,
            ]));
        } catch (QueryException $e) {
            return $this->refused($e) ?? throw $e;
        }

        $this->recompute($exemption);

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
        // Both spans: a re-dated exemption stops excusing the days it left
        // as much as it starts excusing the ones it reached.
        $before = [$exemption->date->toDateString(), $exemption->until->toDateString()];

        try {
            DB::transaction(fn () => $exemption->update($request->validated()));
        } catch (QueryException $e) {
            return $this->refused($e) ?? throw $e;
        }

        $this->recompute($exemption->refresh(), $before);

        return to_route('exemptions.index')->with('success', 'Exemption updated.');
    }

    public function destroy(Exemption $exemption): RedirectResponse
    {
        Gate::authorize('delete', $exemption);

        try {
            DB::transaction(fn () => $exemption->delete());
        } catch (QueryException $e) {
            return $this->refused($e) ?? throw $e;
        }

        $this->recompute($exemption);

        return to_route('exemptions.index')->with('success', 'Exemption removed.');
    }

    private function refused(QueryException $e): ?RedirectResponse
    {
        return match ($e->getCode()) {
            'P0001' => back()->withInput()->with('error', 'Those days fall in a locked month. Unlock the ledger first.'),
            default => null,
        };
    }

    /**
     * @param  list<string>  $also  A span the row also used to occupy.
     */
    private function recompute(Exemption $exemption, array $also = []): void
    {
        $dates = [$exemption->date->toDateString(), $exemption->until->toDateString(), ...$also];

        FanOutRecompute::forEmployees([$exemption->employee_id], min($dates), max($dates));
    }

    private function day(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function types(): array
    {
        return ExemptionType::choices();
    }

    /** @return array<int, array<string, mixed>> */
    private function employees(): array
    {
        return EmployeeResource::collection(
            Employee::query()->orderBy('last_name')->orderBy('first_name')->get()
        )->resolve();
    }
}
