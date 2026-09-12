<?php

namespace App\Http\Controllers;

use App\Actions\CopyDefaults;
use App\Actions\RefreshFromOrigin;
use App\Http\Resources\ScheduleResource;
use App\Http\Resources\ShiftResource;
use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Scopes\AgencyScope;
use App\Models\Shift;
use App\Models\Turn;
use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DefaultController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Shift::class);
        Gate::authorize('viewAny', Schedule::class);

        $platform = Agency::platform();

        $defaultShifts = $this->defaults(Shift::query(), $platform);
        $defaultSchedules = $this->defaults(Schedule::query(), $platform);

        $this->attachCycles($defaultSchedules, $defaultShifts);

        $ownShifts = Shift::query()->get();
        $ownSchedules = Schedule::query()->with('turns.shift')->get();

        return Inertia::render('defaults/index', [
            'shifts' => $defaultShifts
                ->map(fn (Shift $default) => $this->row(
                    $default,
                    $this->counterpart($default, $ownShifts),
                    fn (Shift $copy) => $this->shiftDiffers($copy, $default),
                    fn (Shift $shift) => ShiftResource::make($shift)->resolve(),
                ))
                ->all(),
            'schedules' => $defaultSchedules
                ->map(fn (Schedule $default) => $this->row(
                    $default,
                    $this->counterpart($default, $ownSchedules),
                    fn (Schedule $copy) => $this->scheduleDiffers($copy, $default),
                    fn (Schedule $schedule) => ScheduleResource::make($schedule)->resolve(),
                ))
                ->all(),
        ]);
    }

    public function copy(Request $request, Tenant $tenant, CopyDefaults $defaults): RedirectResponse
    {
        Gate::authorize('create', Shift::class);
        Gate::authorize('create', Schedule::class);

        $agency = $tenant->agency();

        abort_if($agency === null, 404);

        try {
            $summary = $defaults->handle($agency);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            return back()->with('error', 'Somebody was copying the defaults at the same moment. Reload to see what is here now.');
        }

        return back()->with('success', $this->copied($summary));
    }

    public function refresh(Request $request, RefreshFromOrigin $action): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:shift,schedule'],
            'id' => ['required', 'string'],
        ]);

        $copy = $validated['type'] === 'shift'
            ? Shift::query()->find($validated['id'])
            : Schedule::query()->find($validated['id']);

        abort_if($copy === null, 404);

        Gate::authorize('update', $copy);

        if ($copy->origin_id === null) {
            return back()->with('error', "{$copy->name} is your own, not a copy of a default.");
        }

        return $action->handle($copy)
            ? back()->with('success', "{$copy->name} is back to the default.")
            : back()->with('error', "{$copy->name} now uses a shift this agency does not have. Copy the defaults first.");
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return EloquentCollection<int, TModel>
     */
    private function defaults(Builder $query, Agency $platform): EloquentCollection
    {
        return $query
            ->withoutGlobalScopes([AgencyScope::class])
            ->where('agency_id', $platform->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Schedule>  $schedules
     * @param  EloquentCollection<int, Shift>  $shifts
     */
    private function attachCycles(EloquentCollection $schedules, EloquentCollection $shifts): void
    {
        $byId = $shifts->keyBy('id');

        $cycles = Turn::query()
            ->withoutGlobalScopes([AgencyScope::class])
            ->whereIn('schedule_id', $schedules->modelKeys())
            ->orderBy('position')
            ->get()
            ->each(fn (Turn $turn) => $turn->setRelation('shift', $byId->get($turn->shift_id)))
            ->groupBy('schedule_id');

        foreach ($schedules as $schedule) {
            $schedule->setRelation('turns', $cycles->get($schedule->id, new EloquentCollection));
        }
    }

    /**
     * @template TModel of Shift|Schedule
     *
     * @param  TModel  $default
     * @param  EloquentCollection<int, TModel>  $own
     * @return TModel|null
     */
    private function counterpart(Shift|Schedule $default, EloquentCollection $own): Shift|Schedule|null
    {
        return $own->firstWhere('origin_id', $default->id)
            ?? $own->firstWhere('name', $default->name);
    }

    /**
     * @template TModel of Shift|Schedule
     *
     * @param  TModel  $default
     * @param  TModel|null  $copy
     * @param  callable(TModel): bool  $differs
     * @param  callable(TModel): array<string, mixed>  $resolve
     * @return array{default: array<string, mixed>, copy: array<string, mixed>|null, linked: bool, differs: bool}
     */
    private function row(Shift|Schedule $default, Shift|Schedule|null $copy, callable $differs, callable $resolve): array
    {
        $linked = $copy !== null && $copy->origin_id === $default->id;

        return [
            'default' => $resolve($default),
            'copy' => $copy === null ? null : $resolve($copy),
            'linked' => $linked,
            'differs' => $linked && $differs($copy),
        ];
    }

    private function shiftDiffers(Shift $copy, Shift $default): bool
    {
        return $copy->slots != $default->slots
            || (int) $copy->required !== (int) $default->required
            || (int) $copy->flex !== (int) $default->flex
            || (bool) $copy->remote !== (bool) $default->remote
            || (bool) $copy->trust !== (bool) $default->trust;
    }

    /**
     * Length, and the cycle by shift **name**.
     *
     * By name because the two cycles cannot share a shift: the paired FK
     * `(shift_id, agency_id)` puts the copy's turns on the agency's own
     * shifts and the default's on the platform's, so ids never match and only
     * the names are comparable — which is also what `CopyDefaults` preserves.
     */
    private function scheduleDiffers(Schedule $copy, Schedule $default): bool
    {
        return (int) $copy->length !== (int) $default->length
            || $this->cycle($copy) !== $this->cycle($default);
    }

    /**
     * @return list<string|null>
     */
    private function cycle(Schedule $schedule): array
    {
        return $schedule->turns
            ->sortBy('position')
            ->map(fn (Turn $turn) => $turn->shift?->name)
            ->values()
            ->all();
    }

    /**
     * What the copy did, in one sentence.
     *
     * A name left alone is said out loud. It is the one outcome that looks
     * like a failure from the list — the default still reads as uncopied
     * afterwards — so the message names the row rather than leaving the
     * timekeeper to wonder which one did not take.
     *
     * @param  array{shifts: int, schedules: int, kept: list<string>}  $summary
     */
    private function copied(array $summary): string
    {
        $counted = [];

        foreach (['shifts' => 'shift', 'schedules' => 'schedule'] as $key => $noun) {
            if ($summary[$key] > 0) {
                $counted[] = $summary[$key].' '.Str::plural($noun, $summary[$key]);
            }
        }

        $message = $counted === []
            ? 'Every default is already here.'
            : 'Copied '.Arr::join($counted, ', ', ' and ').'.';

        if ($summary['kept'] !== []) {
            $message .= ' Kept your own '.Arr::join($summary['kept'], ', ', ' and ').' — a default cannot take a name you are already using.';
        }

        return $message;
    }
}
