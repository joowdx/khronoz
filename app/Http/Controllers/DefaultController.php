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

/**
 * The platform agency's default shifts and schedules, and the two things an
 * agency may do with them: copy them, and later refresh a copy from the
 * default it came from (04-scheduling.md rule 7).
 *
 * **This is the one screen that reads another agency's rows on purpose.**
 * `Shift` and `Schedule` use the plain `AgencyScope` — unlike `Holiday`,
 * which overrides `agencyScope()` to `AgencyOrPlatformScope` — so a tenant
 * query cannot see a default at all, and the models must stay that way:
 * mixing platform rows into `shifts.index` would put rows nobody may edit in
 * front of every timekeeper. So the scope is dropped **here**, in this
 * controller's own queries, and `agency_id` is named explicitly. The turns of
 * a platform schedule are fetched by their own query for the same reason: an
 * eager load is a fresh query on `Turn`, `AgencyScope` applies to it too, and
 * a platform schedule would come back with an empty cycle rather than an
 * error.
 *
 * **What a row shows.** For each default, whether this agency has the copy,
 * whether the copy is *linked* (its `origin_id` names this default) and
 * whether it has diverged. "Diverged" is `slots`, `required`, `flex`,
 * `remote` and `trust` for a shift; `length` and the ordered turn shift names
 * for a schedule — names, because the two cycles point at different agencies'
 * shifts by construction and only the names are comparable across them.
 *
 * A row may also match by **name** without being linked: the agency had its
 * own `Standard` before it ever copied anything, and `CopyDefaults` leaves
 * such a row alone rather than overwriting or suffixing it (UNIQUE
 * (agency_id, name)). That is shown as its own state, with no Refresh offered
 * — there is no origin to refresh from — so the list never invites an action
 * that cannot succeed.
 *
 * Both writes are POSTs that redirect back with a flash, never JSON: they
 * change rows the page is listing, and the page re-renders them.
 */
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

        // The agency's own rows, under the ordinary tenant scope — this half
        // needs no special handling, and reading it the normal way is what
        // keeps the exception above confined to the platform half.
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

    /**
     * Copy every default this agency does not have yet.
     *
     * One endpoint for the whole set rather than one per row, because that is
     * what the operation is: the schedules cannot be copied without the
     * shifts their turns name, so a per-row copy of a schedule would silently
     * drag its shifts along. `CopyDefaults` is idempotent, so pressing this
     * again after the platform adds a default copies only the addition.
     *
     * The 23505 catch is for the race the pre-check inside the action cannot
     * close: two administrators pressing Copy at the same moment, or a shift
     * created under a default's name between the read and the insert. It
     * needs no `DB::transaction` of its own here — the action already runs
     * inside one, so Postgres has rolled back to before the failed statement
     * and the redirect's own queries are not answering 25P02 (controllers.md).
     */
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

    /**
     * Put one copy back to its default.
     *
     * The row is named in the body rather than in the path because the route
     * is one POST for both tables — `type` says which — and the id is the
     * **agency's own** row, so the tenant scope answers 404 for another
     * agency's copy without this controller having to check anything.
     */
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
     * The platform agency's rows of $query's model.
     *
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
     * Hang each platform schedule's turns, and each turn's shift, on the
     * models by hand.
     *
     * `with('turns.shift')` cannot be used: both relations are fresh queries
     * under `AgencyScope`, so under an agency's tenant every platform
     * schedule would render with an empty cycle. The shifts are already in
     * hand from the same page, so this costs one query and no lookup.
     *
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
     * This agency's row for $default: the linked copy if there is one, else
     * the row that merely shares its name.
     *
     * The name fallback is not a convenience — it is the other half of
     * `CopyDefaults`' collision rule. A default whose name the agency had
     * already used is never copied, so without this the row would read "not
     * copied" forever while Copy went on doing nothing about it.
     *
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
     * One list row: the default, this agency's row for it if any, whether
     * that row is really a copy of *this* default, and whether it diverged.
     *
     * `differs` is only asked of a linked row. An unlinked namesake is the
     * agency's own work and was never claimed to match, so calling it
     * "changed" would be a verdict on something nobody copied.
     *
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

    /**
     * The five columns rule 7's "has diverged" is about.
     *
     * `name` and `color` are deliberately not among them: an agency may
     * rename its copy or move it to a free ramp slot without that being a
     * divergence it should be invited to undo, and `RefreshFromOrigin`
     * restores exactly this set for the same reason.
     *
     * `slots` compares with `!=` rather than `!==`: both sides come back from
     * `jsonb`, which normalises object key order, so the arrays are equal by
     * key and value while their key *order* is nobody's guarantee.
     */
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

    /** @return list<string|null> */
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
