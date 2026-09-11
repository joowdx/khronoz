<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TranslatesUniqueCollisions;
use App\Http\Requests\StoreScheduleRequest;
use App\Http\Requests\UpdateScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Http\Resources\ShiftResource;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Turn;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A repeating cycle of shifts, and the days in it (04-scheduling.md).
 *
 * **The one thing this controller exists to get right**: a schedule and its
 * turns are written in a *single* transaction. `turns_complete` is the
 * schema's only DEFERRABLE INITIALLY DEFERRED constraint — it is checked at
 * COMMIT rather than per row, and it refuses unless exactly `length` turns sit
 * at positions `0 .. length - 1`. Writing the schedule in one transaction and
 * its turns in another therefore fails at the *first* commit, with a schedule
 * that has no turns at all. Deferral is what makes the complete write legal,
 * not what makes the incomplete one survive.
 *
 * That is also why `length` and the turns move together on an update: widening
 * a cycle without adding its new days, or shortening one without dropping its
 * tail, leaves a set that was complete a moment ago and is not any more. Both
 * halves happen inside `writing()`'s transaction, in either order — the check
 * only looks once, at the end.
 *
 * The refusal itself arrives as a `QueryException` from the COMMIT (P0001),
 * and `refused()` turns it into a flash rather than a 500. Every write here
 * runs inside its own `DB::transaction` for the reason `.ai/rules/controllers.md`
 * gives: Postgres marks the surrounding transaction aborted once a statement
 * errors, so a translation that is merely *caught* reports a tidy message on a
 * connection whose next query answers 25P02.
 */
class ScheduleController extends Controller
{
    use TranslatesUniqueCollisions;

    /**
     * Every schedule of the agency with its cycle drawn out.
     *
     * No pagination and no filters: a tenant has a handful of schedules, and
     * the strip is the point of the list — the whole cycle, in order, in the
     * colours the roster grid will use.
     */
    public function index(): Response
    {
        Gate::authorize('viewAny', Schedule::class);

        $schedules = Schedule::query()
            ->with(['turns.shift', 'fallbackShift'])
            ->orderBy('name')
            ->get();

        return Inertia::render('schedules/index', [
            'schedules' => ScheduleResource::collection($schedules)->resolve(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Schedule::class);

        return Inertia::render('schedules/create', [
            'shifts' => fn () => $this->shifts(),
        ]);
    }

    public function store(StoreScheduleRequest $request): RedirectResponse
    {
        $turns = $request->turns();

        try {
            $this->writing(fn () => $this->turns(
                Schedule::create($request->safe()->only(['name', 'length', 'fallback_shift_id'])),
                $turns,
            ));
        } catch (QueryException $e) {
            return $this->refused($e) ?? throw $e;
        }

        return to_route('schedules.index')->with('success', 'Schedule added.');
    }

    public function edit(Schedule $schedule): Response
    {
        Gate::authorize('update', $schedule);

        return Inertia::render('schedules/edit', [
            'schedule' => ScheduleResource::make($schedule->load(['turns.shift', 'fallbackShift']))->resolve(),
            'shifts' => fn () => $this->shifts(),
        ]);
    }

    public function update(UpdateScheduleRequest $request, Schedule $schedule): RedirectResponse
    {
        $turns = $request->turns();

        try {
            $this->writing(function () use ($request, $schedule, $turns): void {
                $schedule->update($request->safe()->only(['name', 'length', 'fallback_shift_id']));

                $this->turns($schedule, $turns);
            });
        } catch (QueryException $e) {
            return $this->refused($e) ?? throw $e;
        }

        return to_route('schedules.index')->with('success', 'Schedule updated.');
    }

    /**
     * A schedule and its turns go together, or neither goes.
     *
     * `turns_complete` tolerates the turns' own DELETEs here because it reads
     * the schedule's length first and returns early when the schedule is gone
     * — which it is, in this same transaction. A schedule a team or anyone's
     * roster still follows is refused by the paired FKs instead (23001), and
     * the whole transaction rolls back with the turns still in place.
     */
    public function destroy(Schedule $schedule): RedirectResponse
    {
        Gate::authorize('delete', $schedule);

        try {
            DB::transaction(function () use ($schedule): void {
                $schedule->turns()->delete();
                $schedule->delete();
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23001') {
                throw $e;
            }

            return to_route('schedules.index')
                ->with('error', "{$schedule->name} cannot be removed while a team or anyone's roster still follows it.");
        }

        return to_route('schedules.index')->with('success', "{$schedule->name} removed.");
    }

    /**
     * Run $write as one transaction, reporting a duplicate name on the field
     * that would have caught it.
     *
     * `translatingCollisions()` supplies the transaction, which is the same
     * one the deferred check fires at: the schedule and every turn are inside
     * it, so COMMIT sees a complete cycle or nothing at all.
     */
    private function writing(callable $write): void
    {
        $this->translatingCollisions(['schedules_agency_id_name_unique' => 'name'], $write);
    }

    /**
     * The cycle's days, `position` taken from the array's order.
     *
     * Shrinking drops the tail first so `UNIQUE (schedule_id, position)` never
     * meets a row it is about to replace, and the surviving turns keep their
     * ids — a turn is a fact about a position, not about a shift, so changing
     * which shift sits at day 3 is an update of day 3 rather than a new day.
     *
     * Both statements are the caller's transaction, never their own: a partial
     * turn set is exactly what `turns_complete` refuses, and it may only ever
     * be transient.
     *
     * @param  array<int, string>  $turns  shift ids, position 0 first
     */
    private function turns(Schedule $schedule, array $turns): void
    {
        $schedule->turns()->where('position', '>=', count($turns))->delete();

        foreach ($turns as $position => $shift) {
            Turn::updateOrCreate(
                ['schedule_id' => $schedule->id, 'position' => $position],
                ['shift_id' => $shift],
            );
        }
    }

    /**
     * The refusal as a message, or null when it is not one of ours.
     *
     * P0001 is `turns_complete` at COMMIT. Both Form Requests already count
     * the turns against `length`, so reaching this means the two disagreed
     * after validation — a concurrent write, or a cycle length the database
     * bounds differently than the rules do. A flash is the only place left to
     * say it: the transaction is over, and the fields it would have belonged
     * to are gone.
     */
    private function refused(QueryException $e): ?RedirectResponse
    {
        return match ($e->getCode()) {
            'P0001' => back()->withInput()->with('error', 'A schedule needs one shift for every day of its cycle. Nothing was saved.'),
            '23514' => back()->withInput()->with('error', 'A cycle runs from 1 to 366 days. Nothing was saved.'),
            default => null,
        };
    }

    /**
     * The shifts a turn may name: this agency's own, never the platform's.
     * `AgencyScope` already draws that line — the defaults are reached through
     * the defaults screen, which copies them in rather than pointing at them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shifts(): array
    {
        return ShiftResource::collection(
            Shift::query()->orderBy('name')->get()
        )->resolve();
    }
}
