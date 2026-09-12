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

class ScheduleController extends Controller
{
    use TranslatesUniqueCollisions;

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
