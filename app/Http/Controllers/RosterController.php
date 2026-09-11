<?php

namespace App\Http\Controllers;

use App\Actions\AssignSchedules;
use App\Attendance\Cycle;
use App\Http\Requests\StoreRosterRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Suspension;
use App\Models\Workgroup;
use App\Support\Minutes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The roster grid: employees down, days across, one month at a time.
 *
 * **It draws the roster as planned, not attendance as it happened.** Holidays
 * and suspensions appear as a wash and a legend note *over* the cycle, never as
 * substituted shifts — rule 5's compressed-week fallback is a computation whose
 * effect belongs on Workdays and the printed form, and a grid that redrew
 * itself for it would stop being a faithful picture of `rosters`, which is the
 * one thing this screen is for.
 *
 * **Why it does not use `Resolver`.** `Resolver` is constructed per employee and
 * `ResolverTest` pins its query count for a range; a hundred-and-twenty-person
 * office would be a hundred and twenty instances. So the whole month is loaded
 * in one query here — every roster *overlapping* the window, with
 * `schedule.turns.shift` eager-loaded — and each cell asks `Cycle::position()`,
 * the same primitive `Resolver` asks. Nothing is duplicated but the loop.
 *
 * Per-cell matching is not an optimisation, it is the correctness condition:
 * people start mid-month, hand over between teams and have gaps, so the roster
 * covering the 3rd need not be the one covering the 24th, and a day covered by
 * none is an empty cell — which is information, and the "Without a roster"
 * section below the grid is how those people get found at all.
 *
 * The window opens **one day before** the month so a night run that began on
 * the last day of the previous month arrives as a band already in progress,
 * drawn clipped at the left edge (08-interface.md §5.23).
 */
class RosterController extends Controller
{
    /**
     * A shift is drawn as a night band when its first slot starts in the
     * evening half of the day and its last slot ends past midnight.
     *
     * The second half alone is not the test. `Duty24` runs 08:00 to 32:00 — it
     * crosses midnight but begins in the morning, and the band geometry opens
     * 12px into its own column because it is positioned for a 22:00 start, so
     * banding it would draw an eight o'clock duty at ten at night in the night
     * ramp, labelled N. A daytime shift spanning days is a different visual
     * problem; it renders as an ordinary chip on each day it covers.
     */
    private const EVENING_FROM = 12 * 60;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Roster::class);

        $month = $this->month($request);
        $workgroup = ($id = $request->string('workgroup')->trim()->toString()) === ''
            ? null
            : Workgroup::find($id);
        $schedule = ($id = $request->string('schedule')->trim()->toString()) === ''
            ? null
            : Schedule::find($id);

        // One day earlier than the month so a run already under way is known.
        $from = $month->subDay();
        $to = $month->endOfMonth()->startOfDay();

        $employees = $this->employees($workgroup);
        $rosters = $this->rosters($employees->modelKeys(), $from, $to, $schedule);
        $days = $this->days($month, $to);
        $calendar = $this->calendar($month, $to);

        $rows = $employees
            ->map(fn (Employee $employee) => $this->row(
                $employee,
                $rosters->get($employee->id) ?? new EloquentCollection,
                $from,
                $days,
            ))
            ->filter()
            ->values();

        return Inertia::render('rosters/index', [
            'days' => $this->dayHeaders($days, $calendar),
            'groups' => $this->grouped($rows),
            'unrostered' => $this->unrostered($employees, $rosters, $to),
            'legend' => $this->legend($rosters),
            'note' => $calendar['note'],
            'filters' => [
                'month' => $month->format('Y-m'),
                'workgroup' => $workgroup?->id ?? '',
                'schedule' => $schedule?->id ?? '',
            ],
            'workgroups' => fn () => Workgroup::query()->orderBy('name')->get(['id', 'name'])->all(),
            'schedules' => fn () => ScheduleResource::collection(
                Schedule::query()->orderBy('name')->get()
            )->resolve(),
        ]);
    }

    /** `YYYY-MM`, defaulting to this month; garbage falls back rather than filtering by it. */
    private function month(Request $request): CarbonImmutable
    {
        $value = $request->string('month')->trim()->toString();

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return now()->toImmutable()->startOfMonth();
        }

        return CarbonImmutable::parse($value.'-01')->startOfMonth();
    }

    /** @return EloquentCollection<int, Employee> */
    private function employees(?Workgroup $workgroup): EloquentCollection
    {
        return Employee::query()
            ->with('currentDeployment.workgroup')
            ->when($workgroup !== null, function (Builder $query) use ($workgroup): void {
                // The filter takes the whole subtree: picking a department and
                // being shown only the people deployed to the department row
                // itself, rather than to its sections, would be a lie.
                $ids = $workgroup->descendants()->pluck('id')->push($workgroup->id);

                $query->whereHas(
                    'currentDeployment',
                    fn (Builder $deployment) => $deployment->whereIn('workgroup_id', $ids),
                );
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * Every roster overlapping the window, keyed by employee.
     *
     * One query, with the cycle eager-loaded to the shift, because the grid
     * then reads thirty-one days per person out of memory.
     *
     * @param  array<int, string>  $employees
     * @return Collection<string, EloquentCollection<int, Roster>>
     */
    private function rosters(array $employees, CarbonImmutable $from, CarbonImmutable $to, ?Schedule $schedule): Collection
    {
        return Roster::query()
            ->with(['schedule.turns.shift', 'team'])
            ->whereIn('employee_id', $employees)
            ->where('starts', '<=', $to->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereNull('ends')
                ->orWhere('ends', '>=', $from->toDateString()))
            ->when($schedule !== null, fn (Builder $query) => $query->where('schedule_id', $schedule->id))
            ->orderBy('starts')
            ->get()
            ->groupBy('employee_id');
    }

    /**
     * The window's dates, the first being the day before the month.
     *
     * @return array<int, CarbonImmutable>
     */
    private function days(CarbonImmutable $month, CarbonImmutable $to): array
    {
        $days = [$month->subDay()];

        for ($day = $month; $day->lte($to); $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    /**
     * Holidays and suspensions over the month, and the one note the legend
     * carries when a suspension was partial.
     *
     * @return array{holidays: array<string, string>, suspensions: array<string, string>, note: ?string}
     */
    private function calendar(CarbonImmutable $month, CarbonImmutable $to): array
    {
        $holidays = Holiday::query()
            ->whereBetween('date', [$month->toDateString(), $to->toDateString()])
            ->get()
            ->mapWithKeys(fn (Holiday $holiday) => [$holiday->date->toDateString() => $holiday->name])
            ->all();

        $suspensions = Suspension::query()
            ->whereBetween('date', [$month->toDateString(), $to->toDateString()])
            ->get();

        $note = null;

        if (($partial = $suspensions->first(fn (Suspension $s) => $s->starts !== null)) !== null) {
            $note = $partial->date->format('j F').', work suspended from '.substr((string) $partial->starts, 0, 5);
        }

        return [
            'holidays' => $holidays,
            'suspensions' => $suspensions
                ->mapWithKeys(fn (Suspension $s) => [$s->date->toDateString() => $s->reason])
                ->all(),
            'note' => $note,
        ];
    }

    /**
     * The day columns, minus the leading day the bands borrowed.
     *
     * @param  array<int, CarbonImmutable>  $days
     * @param  array{holidays: array<string, string>, suspensions: array<string, string>, note: ?string}  $calendar
     * @return array<int, array<string, mixed>>
     */
    private function dayHeaders(array $days, array $calendar): array
    {
        $today = now()->toImmutable()->toDateString();

        return collect($days)
            ->skip(1)
            ->map(function (CarbonImmutable $day) use ($calendar, $today): array {
                $date = $day->toDateString();

                return [
                    'date' => $date,
                    'weekday' => $day->format('D')[0],
                    'day' => $day->day,
                    'weekend' => $day->isWeekend(),
                    'holiday' => $calendar['holidays'][$date] ?? null,
                    'suspension' => $calendar['suspensions'][$date] ?? null,
                    'today' => $date === $today,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One employee's row, or null when no roster touches the window at all —
     * those people belong under "Without a roster", not in the grid as a run
     * of empty cells.
     *
     * @param  EloquentCollection<int, Roster>  $rosters
     * @param  array<int, CarbonImmutable>  $days
     * @return array<string, mixed>|null
     */
    private function row(Employee $employee, EloquentCollection $rosters, CarbonImmutable $from, array $days): ?array
    {
        if ($rosters->isEmpty()) {
            return null;
        }

        $cells = [];
        $nights = [];

        foreach ($days as $day) {
            $roster = $this->covering($rosters, $day);
            $shift = $roster === null ? null : $this->shiftOn($roster, $day);

            $cells[] = $this->cell($shift);
            $nights[] = $shift !== null && $this->isNight($shift);
        }

        // The leading day exists only so a run already under way is known.
        $leading = array_shift($cells);
        $leadingNight = array_shift($nights);

        $covering = $this->covering($rosters, $days[1] ?? $from);

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'number' => $employee->number,
            'workgroup' => $employee->currentDeployment?->workgroup?->name,
            'team_id' => $covering?->team_id,
            'team' => $covering?->team?->name,
            'schedule_id' => $covering?->schedule_id,
            'schedule' => $covering?->schedule?->name,
            'anchor' => $covering?->anchor->toDateString(),
            'length' => $covering?->schedule?->length,
            'cells' => $cells,
            'bands' => $this->bands($nights, $leadingNight, $cells),
            'totals' => [
                'duty' => count(array_filter($cells, fn (array $c) => $c['kind'] === 'shift')),
                'nights' => count(array_filter($nights)),
                'off' => count(array_filter($cells, fn (array $c) => $c['kind'] === 'off')),
            ],
            'leading' => $leading,
        ];
    }

    /** The roster covering a date — `rosters_no_overlap` guarantees at most one. */
    private function covering(EloquentCollection $rosters, CarbonImmutable $day): ?Roster
    {
        return $rosters->first(fn (Roster $roster) => $roster->starts->lte($day)
            && ($roster->ends === null || $roster->ends->gte($day)));
    }

    private function shiftOn(Roster $roster, CarbonImmutable $day): ?Shift
    {
        $schedule = $roster->schedule;

        if ($schedule === null || $schedule->length < 1) {
            return null;
        }

        $position = Cycle::position($day, $roster->anchor, $schedule->length);

        return $schedule->turns->firstWhere('position', $position)?->shift;
    }

    /** @return array<string, mixed> */
    private function cell(?Shift $shift): array
    {
        if ($shift === null) {
            return ['kind' => 'none'];
        }

        $slots = $shift->slots ?? [];

        if ($slots === []) {
            return $shift->remote
                ? ['kind' => 'remote', 'name' => $shift->name]
                : ['kind' => 'off', 'name' => $shift->name];
        }

        return [
            'kind' => 'shift',
            'slot' => $shift->color,
            'letter' => mb_strtoupper(mb_substr($shift->name, 0, 1)),
            'name' => $shift->name,
            'hours' => $this->hours($shift),
        ];
    }

    private function hours(Shift $shift): string
    {
        $slots = $shift->slots ?? [];

        if ($slots === []) {
            return '';
        }

        return $slots[0]['in'].' – '.$slots[count($slots) - 1]['out'];
    }

    private function isNight(Shift $shift): bool
    {
        $slots = $shift->slots ?? [];

        if ($slots === []) {
            return false;
        }

        return Minutes::of($slots[0]['in']) >= self::EVENING_FROM
            && Minutes::of($slots[count($slots) - 1]['out']) > 24 * 60;
    }

    /**
     * Night runs as bands — one band per run, not one per day.
     *
     * A run reaching back past the window's first column is `clipped`: it opens
     * off the left edge rather than pretending it began on the 1st.
     *
     * @param  array<int, bool>  $nights
     * @param  array<int, array<string, mixed>>  $cells
     * @return array<int, array<string, mixed>>
     */
    private function bands(array $nights, bool $leadingNight, array $cells): array
    {
        $bands = [];
        $start = null;

        foreach ($nights as $index => $night) {
            if ($night && $start === null) {
                $start = $index;
            }

            if (! $night && $start !== null) {
                $bands[] = $this->band($start, $index - $start, $cells, $leadingNight);
                $start = null;
            }
        }

        if ($start !== null) {
            $bands[] = $this->band($start, count($nights) - $start, $cells, $leadingNight);
        }

        return $bands;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cells
     * @return array<string, mixed>
     */
    private function band(int $start, int $days, array $cells, bool $leadingNight): array
    {
        return [
            'start' => $start,
            'days' => $days,
            'letter' => $cells[$start]['letter'] ?? 'N',
            'hours' => $cells[$start]['hours'] ?? '',
            'clipped' => $start === 0 && $leadingNight,
        ];
    }

    /**
     * Rows grouped by workgroup and cohort, which is what the grid's group
     * bar names — "Nursing Service, Team B" over "Rotation, 21-day cycle,
     * anchored 7 September".
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function grouped(Collection $rows): array
    {
        return $rows
            ->groupBy(fn (array $row) => ($row['workgroup'] ?? '—').'|'.($row['team_id'] ?? $row['schedule_id'] ?? ''))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $name = $first['workgroup'] ?? 'No workgroup';

                if ($first['team'] !== null) {
                    $name .= ', Team '.$first['team'];
                }

                return [
                    'name' => $name,
                    'meta' => $this->meta($first),
                    'rows' => $rows->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $row */
    private function meta(array $row): string
    {
        if ($row['schedule'] === null) {
            return '';
        }

        $meta = $row['schedule'].', '.$row['length'].'-day cycle';

        return $row['anchor'] === null
            ? $meta
            : $meta.', anchored '.CarbonImmutable::parse($row['anchor'])->format('j F');
    }

    /**
     * Employees no roster covers by the end of the month — the people the grid
     * cannot draw, and therefore the ones most worth naming.
     *
     * @param  EloquentCollection<int, Employee>  $employees
     * @param  Collection<string, EloquentCollection<int, Roster>>  $rosters
     * @return array<int, array<string, mixed>>
     */
    private function unrostered(EloquentCollection $employees, Collection $rosters, CarbonImmutable $to): array
    {
        return $employees
            ->filter(function (Employee $employee) use ($rosters, $to): bool {
                $own = $rosters->get($employee->id);

                return $own === null
                    || $own->every(fn (Roster $roster) => $roster->ends !== null && $roster->ends->lt($to));
            })
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'number' => $employee->number,
                'workgroup' => $employee->currentDeployment?->workgroup?->name,
                'until' => $rosters->get($employee->id)
                    ?->max(fn (Roster $roster) => $roster->ends?->toDateString()),
            ])
            ->values()
            ->all();
    }

    /**
     * Only the marks actually on screen. A legend listing every shift the
     * agency owns would describe the product rather than the month.
     *
     * @param  Collection<string, EloquentCollection<int, Roster>>  $rosters
     * @return array<int, array<string, mixed>>
     */
    private function legend(Collection $rosters): array
    {
        return $rosters
            ->flatten(1)
            ->flatMap(fn (Roster $roster) => $roster->schedule?->turns->pluck('shift')->filter()->all() ?? [])
            ->unique('id')
            ->map(fn (Shift $shift) => [
                'name' => $shift->name,
                'slot' => $shift->color,
                'letter' => mb_strtoupper(mb_substr($shift->name, 0, 1)),
                'hours' => $this->hours($shift),
                'kind' => ($shift->slots ?? []) !== [] ? 'working' : ($shift->remote ? 'remote' : 'off'),
                'night' => $this->isNight($shift),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Issue a roster to everybody selected.
     *
     * `AssignSchedules` is one outer transaction over a call to
     * `AssignSchedule` per person, so a collision on the thirty-ninth of forty
     * assigns nobody — which is the right answer for a bulk action whose
     * partial success nobody could see. It closes each standing roster at
     * `starts - 1` and dispatches the recompute itself; neither is this
     * controller's to do.
     */
    public function store(StoreRosterRequest $request, AssignSchedules $assign): RedirectResponse
    {
        $schedule = Schedule::findOrFail($request->string('schedule_id')->toString());
        $employees = Employee::query()->whereIn('id', $request->array('employees'))->get();

        try {
            $assign->handle(
                $employees,
                $schedule,
                CarbonImmutable::parse($request->string('anchor')->toString()),
                CarbonImmutable::parse($request->string('starts')->toString()),
                ($ends = $request->string('ends')->trim()->toString()) === ''
                    ? null
                    : CarbonImmutable::parse($ends),
            );
        } catch (QueryException $exception) {
            return $this->refused($exception) ?? throw $exception;
        }

        $count = $employees->count();

        return back()->with('success', $count === 1
            ? 'Assigned '.$schedule->name.' to one employee.'
            : 'Assigned '.$schedule->name.' to '.$count.' employees.');
    }

    /**
     * A wrongly dated roster is deleted and reissued rather than amended
     * (decision 35) — so this is the correction path, not an alternative to
     * assigning.
     */
    public function destroy(Roster $roster): RedirectResponse
    {
        Gate::authorize('delete', $roster);

        try {
            DB::transaction(fn () => $roster->delete());
        } catch (QueryException $exception) {
            return $this->refused($exception) ?? throw $exception;
        }

        return back()->with('success', 'Roster removed.');
    }

    /**
     * What the database refused, in words.
     *
     * Wrapped by the caller rather than caught around a bare statement: a
     * failed statement leaves the surrounding transaction aborted (25P02), and
     * a tidy message on a connection nothing else can use is a lie
     * (.ai/rules/controllers.md).
     */
    private function refused(QueryException $exception): ?RedirectResponse
    {
        return match ($exception->getCode()) {
            // rosters_no_overlap. Deliberately not pre-checked — the exclusion
            // constraint is the only authority, and a pre-check would race it.
            '23P01' => back()->withInput()->with(
                'error',
                'Somebody selected is already rostered over those dates. End the standing roster first, or start this one later.',
            ),
            '23001' => back()->with('error', 'That roster cannot be removed while something still refers to it.'),
            'P0001' => back()->withInput()->with('error', 'The database refused that assignment.'),
            default => null,
        };
    }
}
