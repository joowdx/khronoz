<?php

namespace App\Http\Controllers;

use App\Enums\PunchKind;
use App\Enums\WorkdayStatus;
use App\Http\Resources\EmployeeResource;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Ledger;
use App\Models\Overtime;
use App\Models\Punch;
use App\Models\Roster;
use App\Models\Terminal;
use App\Models\Timelog;
use App\Models\Workday;
use App\Support\Minutes;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @see resources/js/pages/dashboard.tsx for the interfaces every prop below matches.
 */
class DashboardController extends Controller
{
    private const DAY_OPENS_AT_HOUR = 6;

    private const DAY_MINUTES = 1440;

    private const EVENTS = 12;

    private const WORKGROUPS = 5;

    public function __construct(private Tenant $tenant) {}

    public function __invoke(Request $request): Response
    {
        $agency = $this->tenant->agency();
        $user = $request->user();

        $counts = [
            'users' => $agency->users()->count(),
            'active' => $agency->users()->whereNotNull('email_verified_at')->count(),

            'invited' => $agency->users()->whereNotNull('invited_at')->whereNull('email_verified_at')->count(),
        ];

        if ($agency->platform) {

            $agencies = Agency::query()->withCount('users')->get();

            $counts += [
                'agencies' => $agencies->count(),
                'agency_users' => (int) $agencies->sum('users_count'),
                'empty_agencies' => $agencies->where('users_count', 0)->count(),
            ];

            return Inertia::render('dashboard', ['counts' => $counts]);
        }

        $now = CarbonImmutable::now();
        $today = $this->openWindow($now);
        $month = $this->month($request);
        [$through, $days] = $this->elapsed($month, $today);
        [$comparison, $comparisonThrough] = $this->comparison($month, $days);

        $records = $user->can('viewAny', Ledger::class);
        $scheduling = $user->can('viewAny', Roster::class);
        $terminals = $user->can('viewAny', Terminal::class);

        if ($terminals) {
            $counts['unresolved_timelogs'] = Timelog::query()->standing()->unresolved()->count();
        }

        if ($scheduling) {
            $counts['without_roster'] = $this->withoutRoster($today);
        }

        $nightOuts = $records ? $this->nightOuts($today, $now) : null;
        $ledgers = $records ? $this->ledgers($month) : null;

        return Inertia::render('dashboard', array_filter([
            'counts' => $counts,
            'month' => $records ? [
                'value' => $month->format('Y-m'),
                'previous' => $comparison->format('Y-m'),
                'through' => $through?->toDateString(),
                'days' => $days,
            ] : null,
            'figures' => $records ? $this->figures($month, $through, $comparison, $comparisonThrough) : null,
            'ledgers' => $ledgers,
            'today' => $records ? $this->today($today, $now) : null,
            'night_outs' => $nightOuts,
            'tardiness' => $records ? $this->tardiness($month, $through) : null,
            'duty' => $scheduling ? ['date' => $today->toDateString(), 'lanes' => $this->lanes($today)] : null,
        ], fn (mixed $value): bool => $value !== null));
    }

    private function openWindow(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subHours(self::DAY_OPENS_AT_HOUR)->startOfDay();
    }

    private function month(Request $request): CarbonImmutable
    {
        $value = $request->string('month')->trim()->toString();

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return now()->toImmutable()->startOfMonth();
        }

        return CarbonImmutable::parse($value.'-01')->startOfMonth();
    }

    /**
     * The last day of `$month` that has actually happened, and how many days that is;
     * null and zero for a month that has not started. A month still running must be
     * compared over the days it has, or the 9th of September reads as a collapse
     * against the whole of August.
     *
     * @return array{0: ?CarbonImmutable, 1: int}
     */
    private function elapsed(CarbonImmutable $month, CarbonImmutable $today): array
    {
        $end = $month->endOfMonth()->startOfDay();

        if ($today->lt($month)) {
            return [null, 0];
        }

        $through = $today->lt($end) ? $today : $end;

        return [$through, (int) $through->day];
    }

    /**
     * The month before, cut to the same number of days.
     *
     * `min()` against its own length is load-bearing: the same 31 days of
     * March do not exist in February, and asking for them would compare a
     * whole month against a short one.
     *
     * @return array{0: CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function comparison(CarbonImmutable $month, int $days): array
    {
        $previous = $month->subMonth()->startOfMonth();
        $length = min($days, $previous->daysInMonth);

        return [$previous, $length === 0 ? null : $previous->setDay($length)];
    }

    /**
     * @return array<string, array{value: int, previous: int}>
     */
    private function figures(
        CarbonImmutable $month,
        ?CarbonImmutable $through,
        CarbonImmutable $comparison,
        ?CarbonImmutable $comparisonThrough,
    ): array {
        $now = $this->count($month, $through);
        $before = $this->count($comparison, $comparisonThrough);
        $figures = [];

        foreach ($now as $key => $value) {
            $figures[$key] = ['value' => $value, 'previous' => $before[$key]];
        }

        return $figures;
    }

    /**
     * One window's five counts: four conditional aggregates over `workdays`
     * in a single pass, plus the authorities filed in the same days.
     *
     * `count(*) filter (where …)` rather than four queries or four `CASE`
     * sums — the aggregate reads as the question it answers and Postgres
     * scans the month once.
     *
     * @return array<string, int>
     */
    private function count(CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if ($to === null) {
            return ['workdays' => 0, 'tardy' => 0, 'undertime' => 0, 'absent' => 0, 'overtime' => 0];
        }

        $window = [$from->toDateString(), $to->toDateString()];

        $row = Workday::query()
            ->whereBetween('date', $window)
            ->selectRaw('count(*) as workdays')
            ->selectRaw('count(*) filter (where tardy > 0) as tardy')
            ->selectRaw('count(*) filter (where undertime > 0) as undertime')
            ->selectRaw('count(*) filter (where status = ?) as absent', [WorkdayStatus::Absent->value])
            ->toBase()
            ->first();

        return [
            'workdays' => (int) $row->workdays,
            'tardy' => (int) $row->tardy,
            'undertime' => (int) $row->undertime,
            'absent' => (int) $row->absent,

            'overtime' => Overtime::query()->whereBetween('date', $window)->count(),
        ];
    }

    /**
     * `lockable` exactly matches the database lock condition; the four states partition the total.
     *
     * @return array{total: int, open: int, lockable: int, locked: int, attested: int}
     */
    private function ledgers(CarbonImmutable $month): array
    {
        $of = fn (): Builder => Ledger::query()->where('month', $month->toDateString());

        $total = $of()->count();
        $attested = $of()->has('attestations')->count();
        $locked = $of()->whereNotNull('locked_at')->doesntHave('attestations')->count();
        $lockable = $of()
            ->whereNull('locked_at')
            ->whereDoesntHave('workdays.punches', fn (Builder $query) => $query->where('expected_at', '>', now()))
            ->count();

        return [
            'total' => $total,
            'open' => $total - $attested - $locked - $lockable,
            'lockable' => $lockable,
            'locked' => $locked,
            'attested' => $attested,
        ];
    }

    /**
     * People with nobody's timetable against their name today.
     *
     * `exempt` is excluded because no daily time record is expected of them
     * (01-organization.md), so a roster would be the anomaly rather than the
     * gap. Soft-deleted employees are excluded by their own scope: somebody
     * who has left needs no schedule.
     */
    private function withoutRoster(CarbonImmutable $today): int
    {
        return Employee::query()
            ->where('exempt', false)
            ->whereDoesntHave('rosters', fn (Builder $query) => $query->covering($today))
            ->count();
    }

    /**
     * Night shifts that have not clocked out: an out side with no `actual_at`, due inside
     * the window that is open now, on a workday dated before it — which is precisely a
     * shift that began yesterday and should have ended this morning (decision 54). An out
     * still in the future is not late, and one due in an earlier window is history.
     *
     * @return list<array<string, mixed>>
     */
    private function nightOuts(CarbonImmutable $today, CarbonImmutable $now): array
    {
        return Punch::query()
            ->where('kind', PunchKind::Out)
            ->whereNull('actual_at')
            ->whereNotNull('expected_at')
            ->whereBetween('expected_at', [$today->addHours(self::DAY_OPENS_AT_HOUR), $now])
            ->whereHas('workday', fn (Builder $query) => $query->where('date', '<', $today->toDateString()))
            ->with(['workday', 'employee' => $this->person(...)])
            ->orderBy('expected_at')
            ->get()
            ->map(fn (Punch $punch): array => [
                'id' => $punch->id,
                'employee' => $this->employee($punch->employee),
                'shift' => $this->hours($punch->workday?->shift['shift']['slots'] ?? []),
                'since' => $punch->expected_at->format('H:i'),
            ])
            ->all();
    }

    /**
     * @return array{total: int, events: list<array<string, mixed>>}
     */
    private function today(CarbonImmutable $today, CarbonImmutable $now): array
    {
        $date = $today->toDateString();
        $onThisDay = fn (Builder $query) => $query->where('date', $date);

        $late = Punch::query()
            ->where('kind', PunchKind::In)
            ->whereNotNull('actual_at')
            ->where('deviation', '>', 0)
            ->whereHas('workday', $onThisDay)
            ->with(['employee' => $this->person(...)])
            ->get()
            ->map(fn (Punch $punch): array => $this->event(
                'late_in',
                'Late in',
                $punch->employee,
                $punch->expected_at === null ? null : 'Expected '.$punch->expected_at->format('H:i'),
                $punch->actual_at->format('H:i'),
                $punch->actual_at->format('Y-m-d H:i:s'),
                $punch->id,
            ));

        $missed = Punch::query()
            ->where('kind', PunchKind::Out)
            ->whereNull('actual_at')
            ->whereNotNull('expected_at')
            ->where('expected_at', '<=', $now)
            ->whereHas('workday', $onThisDay)
            ->with(['workday', 'employee' => $this->person(...)])
            ->get()
            ->map(fn (Punch $punch): array => $this->event(
                'missed_out',
                'Missed out',
                $punch->employee,

                ($name = $punch->workday?->shift['shift']['name'] ?? null) === null
                    ? 'No out punch'
                    : $name.' shift, no out punch',
                $punch->expected_at->format('H:i'),
                $punch->expected_at->format('Y-m-d H:i:s'),
                $punch->id,
            ));

        $leave = Exemption::query()
            ->covering($today)
            ->with(['employee' => $this->person(...)])
            ->get()
            ->map(fn (Exemption $exemption): array => $this->event(
                'leave',
                'On leave',
                $exemption->employee,
                $exemption->type->label(),
                $exemption->wholeDay()
                    ? 'Whole day'
                    : substr((string) $exemption->starts, 0, 5).' – '.substr((string) $exemption->ends, 0, 5),
                $date.' '.($exemption->starts ?? '00:00:00'),
                $exemption->id,
            ));

        $overtime = Overtime::query()
            ->startingOn($today)
            ->with(['employee' => $this->person(...)])
            ->get()
            ->map(fn (Overtime $authority): array => $this->event(
                'overtime',
                'Overtime authorised',
                $authority->employee,
                $authority->purpose,
                $authority->starts->format('H:i').' – '.$authority->ends->format('H:i'),
                $authority->starts->format('Y-m-d H:i:s'),
                $authority->id,
            ));

        $events = $late->concat($missed)->concat($leave)->concat($overtime)->sortBy('at')->values();

        return [
            'total' => $events->count(),
            'events' => $events->take(self::EVENTS)->values()->all(),
        ];
    }

    /**
     * Report tardiness against the current substantive placement, not a reassignment.
     *
     * @return array{total: int, workgroups: list<array{id: string, name: string, count: int}>}
     */
    private function tardiness(CarbonImmutable $month, ?CarbonImmutable $through): array
    {
        if ($through === null) {
            return ['total' => 0, 'workgroups' => []];
        }

        $today = $this->openWindow(CarbonImmutable::now())->toDateString();
        $window = [$month->toDateString(), $through->toDateString()];

        $rows = Workday::query()
            ->join('employees', 'employees.id', '=', 'workdays.employee_id')
            ->join('deployments', function (JoinClause $join) use ($today): void {
                $join->on('deployments.employee_id', '=', 'employees.id')
                    ->whereNull('deployments.parent_id')
                    ->where('deployments.starts', '<=', $today)
                    ->where(function (JoinClause $open) use ($today): void {
                        $open->whereNull('deployments.ends')->orWhere('deployments.ends', '>=', $today);
                    });
            })
            ->join('workgroups', 'workgroups.id', '=', 'deployments.workgroup_id')
            ->whereBetween('workdays.date', $window)
            ->where('workdays.tardy', '>', 0)
            ->groupBy('workgroups.id', 'workgroups.name')
            ->select('workgroups.id', 'workgroups.name')
            ->selectRaw('count(*) as occurrences')
            ->orderByDesc('occurrences')
            ->orderBy('workgroups.name')
            ->toBase()
            ->get();

        return [

            'total' => (int) $rows->sum('occurrences'),
            'workgroups' => $rows
                ->take(self::WORKGROUPS)
                ->map(fn (object $row): array => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'count' => (int) $row->occurrences,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array{id: string, name: string, slot: int, count: int, bars: list<array{from: int, to: int, label: string}>}>
     */
    private function lanes(CarbonImmutable $today): array
    {
        $date = $today->toDateString();

        $rows = Roster::query()
            ->join('schedules', 'schedules.id', '=', 'rosters.schedule_id')
            ->join('turns', function (JoinClause $join) use ($date): void {
                $join->on('turns.schedule_id', '=', 'schedules.id')
                    ->whereRaw(
                        'turns.position = (((?::date - rosters.anchor) % schedules.length) + schedules.length) % schedules.length',
                        [$date],
                    );
            })
            ->join('shifts', 'shifts.id', '=', 'turns.shift_id')
            ->join('employees', 'employees.id', '=', 'rosters.employee_id')

            ->whereNull('employees.deleted_at')
            ->where('employees.exempt', false)
            ->where('rosters.starts', '<=', $date)
            ->where(function (Builder $open) use ($date): void {
                $open->whereNull('rosters.ends')->orWhere('rosters.ends', '>=', $date);
            })
            ->groupBy('shifts.id', 'shifts.name', 'shifts.color', 'shifts.slots')
            ->select('shifts.id', 'shifts.name', 'shifts.color', 'shifts.slots')
            ->selectRaw('count(*) as people')
            ->orderBy('shifts.name')
            ->toBase()
            ->get();

        $lanes = [];

        foreach ($rows as $row) {
            $bars = $this->bars(json_decode((string) $row->slots, true) ?? []);

            if ($bars === []) {
                continue;
            }

            $lanes[] = [
                'id' => $row->id,
                'name' => $row->name,

                'slot' => (int) $row->color,
                'count' => (int) $row->people,
                'bars' => $bars,
            ];
        }

        return $lanes;
    }

    /**
     * Use minute offsets so overnight slots remain exact; clamp bars to the display window.
     *
     * @param  mixed  $slots  the shift's `slots` json, shape-checked by slots_valid()
     * @return list<array{from: int, to: int, label: string}>
     */
    private function bars(mixed $slots): array
    {
        $bars = [];

        foreach (is_array($slots) ? $slots : [] as $slot) {
            $in = Minutes::of($slot['in']);
            $out = Minutes::of($slot['out']);
            $from = $in - self::DAY_OPENS_AT_HOUR * 60;
            $to = $out - self::DAY_OPENS_AT_HOUR * 60;

            if ($to <= 0 || $from >= self::DAY_MINUTES) {
                continue;
            }

            $bars[] = [
                'from' => max(0, $from),
                'to' => min(self::DAY_MINUTES, $to),
                'label' => $this->clock($in).' – '.$this->clock($out),
            ];
        }

        return $bars;
    }

    /**
     * The first in to the last out of a set of slots, as `22:00 – 06:00`; null for a shift with none.
     */
    private function hours(mixed $slots): ?string
    {
        if (! is_array($slots) || $slots === []) {
            return null;
        }

        return $this->clock(Minutes::of($slots[array_key_first($slots)]['in']))
            .' – '
            .$this->clock(Minutes::of($slots[array_key_last($slots)]['out']));
    }

    /**
     * Minutes past midnight as a wall clock, wrapping past 24:00: 1800 is `06:00`.
     */
    private function clock(int $minutes): string
    {
        $wrapped = $minutes % self::DAY_MINUTES;

        return sprintf('%02d:%02d', intdiv($wrapped, 60), $wrapped % 60);
    }

    /**
     * How every screen in this application loads the person on an attendance
     * row: `withTrashed()`, because a DTR is a historical pay record and
     * `RemoveEmployee` soft-deletes somebody whose final month still has to
     * be locked and signed, plus the placement the row's second line names.
     *
     * @param  BelongsTo<Employee, covariant \Illuminate\Database\Eloquent\Model>  $employee
     */
    private function person(BelongsTo $employee): void
    {
        $employee->withTrashed()->with('currentDeployment.workgroup');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function employee(?Employee $employee): ?array
    {
        return $employee === null ? null : EmployeeResource::make($employee)->resolve();
    }

    /**
     * One row of today's table.
     *
     * `at` is the sort key and never reaches the screen — the four sources
     * are ordered against each other by it, and `time` is what is read.
     *
     * @return array<string, mixed>
     */
    private function event(
        string $kind,
        string $label,
        ?Employee $employee,
        ?string $detail,
        string $time,
        string $at,
        string $id,
    ): array {
        return [
            'id' => $id,
            'kind' => ['value' => $kind, 'label' => $label],
            'employee' => $this->employee($employee),
            'detail' => $detail,
            'time' => $time,
            'at' => $at,
        ];
    }
}
