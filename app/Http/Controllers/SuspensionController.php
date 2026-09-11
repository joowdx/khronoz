<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSuspensionRequest;
use App\Http\Requests\UpdateSuspensionRequest;
use App\Http\Resources\SuspensionResource;
use App\Http\Resources\WorkgroupResource;
use App\Models\Suspension;
use App\Models\Workgroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Work suspensions: a typhoon, a brownout, a transport strike (05-calendar.md).
 *
 * `workgroup_id` null is **agency-wide** and is the commonest shape — a
 * typhoon closes the office, not one division. Naming a workgroup means that
 * workgroup *and everything under it* (01-organization.md rule 4), which the
 * form says out loud because the picker cannot show a subtree.
 *
 * `user_id` is never a form field: it is the acting user, taken from the
 * request. The `actor_of_agency` trigger (decision 39) refuses a user from
 * some third agency, and a picker would be inviting exactly the row that
 * trigger exists to refuse.
 */
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
            // Who declared it. Never sent by the client — see the class
            // docblock and decision 39.
            'user_id' => $request->user()->id,
        ]);

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
        $suspension->update($request->validated());

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
        $suspension->delete();

        return to_route('suspensions.index', ['year' => $year])->with('success', 'Suspension withdrawn.');
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
