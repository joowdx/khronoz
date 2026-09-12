<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TranslatesUniqueCollisions;
use App\Http\Requests\StoreShiftRequest;
use App\Http\Requests\UpdateShiftRequest;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    use TranslatesUniqueCollisions;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Shift::class);

        $shifts = Shift::query()
            ->orderBy('name')
            ->get();

        return Inertia::render('shifts/index', [
            'shifts' => ShiftResource::collection($shifts)->resolve(),

            'usedColors' => $shifts->pluck('color')->unique()->sort()->values()->all(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Shift::class);

        return Inertia::render('shifts/create', [
            'usedColors' => fn () => $this->usedColors(),
        ]);
    }

    public function store(StoreShiftRequest $request): RedirectResponse
    {
        $shift = $this->translatingCollisions(
            ['shifts_agency_id_name_unique' => 'name'],
            fn () => Shift::create($request->validated()),
        );

        return to_route('shifts.index')->with('success', 'Shift added.');
    }

    public function edit(Shift $shift): Response
    {
        Gate::authorize('update', $shift);

        return Inertia::render('shifts/edit', [
            'shift' => ShiftResource::make($shift)->resolve(),
            'usedColors' => fn () => $this->usedColors(),
        ]);
    }

    public function update(UpdateShiftRequest $request, Shift $shift): RedirectResponse
    {
        $this->translatingCollisions(
            ['shifts_agency_id_name_unique' => 'name'],
            fn () => $shift->update($request->validated()),
        );

        return to_route('shifts.index')->with('success', 'Shift updated.');
    }

    public function destroy(Shift $shift): RedirectResponse
    {
        Gate::authorize('delete', $shift);

        try {
            DB::transaction(fn () => $shift->delete());
        } catch (QueryException $e) {
            return $this->refused($e) ?? throw $e;
        }

        return to_route('shifts.index')->with('success', 'Shift removed.');
    }

    private function refused(QueryException $e): ?RedirectResponse
    {
        return match ($e->getCode()) {
            '23001' => back()->with('error', 'That shift is still used by one or more schedule turns. Reassign or remove those turns first.'),
            default => null,
        };
    }

    /**
     * @return array<int, int>
     */
    private function usedColors(): array
    {
        return Shift::query()
            ->select('color')
            ->distinct()
            ->orderBy('color')
            ->pluck('color')
            ->map(fn ($c) => (int) $c)
            ->all();
    }
}
