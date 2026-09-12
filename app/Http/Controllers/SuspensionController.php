<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSuspensionRequest;
use App\Http\Requests\UpdateSuspensionRequest;
use App\Http\Resources\SuspensionResource;
use App\Http\Resources\WorkgroupResource;
use App\Jobs\FanOutRecompute;
use App\Models\Suspension;
use App\Models\Workgroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SuspensionController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Suspension::class);

        $year = $this->year($request->string('year')->trim()->toString());

        $suspensions = Suspension::query()
            ->with(['workgroup', 'user'])
            ->whereYear('date', $year)
            ->orderByDesc('date')
            ->get();

        return Inertia::render('suspensions/index', [
            'suspensions' => SuspensionResource::collection($suspensions)->resolve(),
            'filters' => ['year' => (string) $year],
            'years' => fn () => $this->years(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Suspension::class);

        return Inertia::render('suspensions/create', [
            'workgroups' => fn () => $this->workgroups(),
        ]);
    }

    public function store(StoreSuspensionRequest $request): RedirectResponse
    {
        $suspension = Suspension::create([
            ...$request->validated(),

            'user_id' => $request->user()->id,
        ]);

        $this->recompute($this->reach($suspension));

        return to_route('suspensions.index', ['year' => $suspension->date->year])
            ->with('success', 'Suspension declared.');
    }

    public function edit(Suspension $suspension): Response
    {
        Gate::authorize('update', $suspension);

        return Inertia::render('suspensions/edit', [
            'suspension' => SuspensionResource::make($suspension)->resolve(),
            'workgroups' => fn () => $this->workgroups(),
        ]);
    }

    public function update(UpdateSuspensionRequest $request, Suspension $suspension): RedirectResponse
    {

        // workgroup stops closing the office it left.
        $before = $this->reach($suspension);

        $suspension->update($request->validated());

        $this->recompute($before);
        $this->recompute($this->reach($suspension->refresh()), $before);

        return to_route('suspensions.index', ['year' => $suspension->date->year])
            ->with('success', 'Suspension updated.');
    }

    /**
     * Nothing references a suspension, so this is a plain delete. The deriver
     * reads the table by date at compute time rather than holding a key to it,
     * which is what makes withdrawing a declaration safe.
     */
    public function destroy(Suspension $suspension): RedirectResponse
    {
        Gate::authorize('delete', $suspension);

        $year = $suspension->date->year;
        $reach = $this->reach($suspension);
        $suspension->delete();

        $this->recompute($reach);

        return to_route('suspensions.index', ['year' => $year])->with('success', 'Suspension withdrawn.');
    }

    /**
     * @return array{agency: ?string, employees: list<string>, date: string}
     */
    private function reach(Suspension $suspension): array
    {
        $employees = $suspension->workgroup_id === null
            ? []
            : $suspension->appliesTo()->orderBy('id')->pluck('id')->all();

        return [
            'agency' => $suspension->workgroup_id === null ? $suspension->agency_id : null,
            'employees' => $employees,
            'date' => $suspension->date->toDateString(),
        ];
    }

    /**
     * @param  array{agency: ?string, employees: list<string>, date: string}  $reach
     * @param  ?array{agency: ?string, employees: list<string>, date: string}  $unless  Already queued.
     */
    private function recompute(array $reach, ?array $unless = null): void
    {
        if ($reach === $unless) {
            return;
        }

        $reach['agency'] === null
            ? FanOutRecompute::forEmployees($reach['employees'], $reach['date'], $reach['date'])
            : FanOutRecompute::forAgency($reach['agency'], $reach['date'], $reach['date']);
    }

    private function year(string $value): int
    {
        return preg_match('/^\d{4}$/', $value) === 1 ? (int) $value : (int) today()->year;
    }

    /** @return array<int, array<string, mixed>> */
    private function workgroups(): array
    {
        return WorkgroupResource::collection(Workgroup::query()->orderBy('name')->get())->resolve();
    }

    /** @return array<int, string> */
    private function years(): array
    {
        return Suspension::query()
            ->selectRaw('distinct extract(year from date)::int as year')
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn (int $year) => (string) $year)
            ->all();
    }
}
