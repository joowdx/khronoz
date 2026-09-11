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

/**
 * Day templates the scheduler assigns to roster turns (04-scheduling.md).
 *
 * Three kinds, derived server-side by ShiftResource so every surface reads the
 * same answer:
 *   - **working** — has slots (in/out pairs, possibly past 24:00).
 *   - **off**     — no slots, not remote; expects nothing, credits nothing.
 *   - **remote**  — no slots, `remote` true; credited on attestation (Flexiplace).
 *
 * `shifts` is read under the plain `AgencyScope`, not `AgencyOrPlatformScope`,
 * so a platform-owned default row never reaches an agency query here. Editing
 * the platform defaults happens through a different screen (the defaults list),
 * which is why there is no platform-row guard in ShiftPolicy.
 *
 * The `UNIQUE (agency_id, name)` constraint is the only refusal this
 * controller needs to translate on write — the four CHECK constraints that
 * relate `slots`, `remote`, `required` and `flex` are owned by a later
 * hardening pass and are intentionally left untranslated here. The 23001 on
 * destroy is translated: `turns.shift_id` RESTRICT-references this table, so
 * removing a shift a turn uses is refused by the database rather than cascaded.
 */
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
            // The ramp indices the agency already uses, so the colour picker on
            // the create/edit form can mark them as taken. Sent as an array of
            // integers 1–8 rather than a Set so it is plain JSON.
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

    /**
     * Remove the shift, translating the RESTRICT refusal into a message.
     *
     * `turns.shift_id` RESTRICT-references `shifts.id`, so removing a shift
     * that any schedule turn still references is refused with SQLSTATE 23001.
     * Without a nested transaction the caught exception leaves the surrounding
     * Postgres transaction aborted (25P02), poisoning the redirect's own
     * queries — controllers.md's standing rule.
     */
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

    /**
     * The refusal as a redirect message, or null when it is not one of ours.
     *
     * SQLSTATE 23001 (`ON DELETE RESTRICT`) fires when a schedule turn still
     * references this shift. The message names what still uses the shift so
     * the user knows what to change first.
     */
    private function refused(QueryException $e): ?RedirectResponse
    {
        return match ($e->getCode()) {
            '23001' => back()->with('error', 'That shift is still used by one or more schedule turns. Reassign or remove those turns first.'),
            default => null,
        };
    }

    /**
     * The ramp indices (1–8) this agency's shifts already occupy, sorted.
     *
     * Used by the colour picker so it can mark taken slots without a second
     * query on the create/edit forms. Sent as a lazy closure so Inertia only
     * resolves it on full page loads, not on partial reloads that do not ask
     * for it.
     *
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
