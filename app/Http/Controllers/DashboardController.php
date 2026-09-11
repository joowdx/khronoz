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
 * The signed-in landing page.
 *
 * Everything it reports is a fact about the current tenant, gathered here so
 * the page composes rather than queries. Two shapes, by tenant:
 *
 * - The **platform** tenant sees the estate and nothing else: how many
 *   `agencies` exist, how many `agency_users` they hold between them, and how
 *   many of those agencies are still `empty_agencies`. Its own `users` are the
 *   superusers, which is why there is no separate key for them. It gets no
 *   attendance section at all — `employees` carries an `agency_not_platform`
 *   trigger, so the platform row can hold no people and every figure below
 *   would be a measured zero pretending to be news.
 * - An **agency** tenant sees its own people plus the month: the figure strip
 *   against the same days of the month before, the ledger split, today's
 *   events, pending night outs, tardiness by workgroup, and the lane chart's
 *   data.
 *
 * `agencies` keys off the *tenant's* platform flag, not the user's, so a
 * platform user who has entered an agency works inside it exactly like its own
 * staff (docs/design/02-access.md rule 3).
 *
 * **The month is a query-string filter** (`?month=YYYY-MM`, defaulting to the
 * current month and dropped when it is the default), read the same way
 * WorkdayController and LedgerController read theirs, so the page is a link
 * and the heading row can carry §5.2's stepper.
 *
 * **Every section is gated on the right that owns its screen**, not merely
 * hidden in the page: a figure a colleague may not follow through to is one
 * they may not have. `ledgers.view` owns the month, the ledgers and today;
 * `scheduling.view` owns the roster gap and the lane data; `terminals.view`
 * owns the unresolved timelogs. A section the viewer may not see is **absent**
 * from the props, never zero.
 *
 * **A day runs 06:00 to 30:00** (docs/design/08-interface.md §5.3), so
 * "today" here is the open day window's date and not the calendar date: at
 * 02:00 the window that is open is still yesterday's, which is also the date a
 * night shift's workday carries (decision 54). `resources/js/hooks/use-manila-clock.ts`
 * reads the same rule off the clock and sends it back as `date`.
 *
 * @see resources/js/pages/dashboard.tsx for the interfaces every prop below matches.
 */
class DashboardController extends Controller
{
    /** A day opens at 06:00 — the product's one time scale, mirrored by hooks/use-manila-clock.ts. */
    private const DAY_OPENS_AT_HOUR = 6;

    /** The 06:00 → 30:00 window, in minutes; every lane bar is positioned inside it. */
    private const DAY_MINUTES = 1440;

    /** Today's table is a summary, not a log: it names the total and lists this many. */
    private const EVENTS = 12;

    /** The meter rows §6.3 draws; the rest of the tail is noise at that height. */
    private const WORKGROUPS = 5;

    public function __construct(private Tenant $tenant) {}

    public function __invoke(Request $request): Response
    {
        $agency = $this->tenant->agency();
        $user = $request->user();

        // Always through the tenant's own agency relation, never a bare
        // User::query() — User carries no tenant scope (app/Models/User.php,
        // .ai/rules/models.md), so a bare query would count every user of
        // every agency instead of just this one. Three cheap counts rather
        // than one clever one: a relation cannot be cloned safely (Relation
        // has no __clone, so two clones share one Builder).
        $counts = [
            'users' => $agency->users()->count(),
            'active' => $agency->users()->whereNotNull('email_verified_at')->count(),
            // Accepting an invitation verifies the address on the way through
            // (Auth\InviteController), so "still invited" is exactly "invited
            // and not yet verified".
            'invited' => $agency->users()->whereNotNull('invited_at')->whereNull('email_verified_at')->count(),
        ];

        if ($agency->platform) {
            // One query for all three platform figures. Agency's own
            // NotPlatformScope keeps the platform row out, so these count real
            // agencies, and withCount avoids a query per agency.
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

        // The remaining two attention rows are counts of a section this page
        // already sends whole, so they are not repeated into `counts`: the
        // pending night outs are that list's own length and the lockable
        // ledgers are the split's own figure. One fact, one place — a second
        // copy of a number is a number that can disagree with itself.
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

    /**
     * The date of the day window that is open at `$now`.
     *
     * Six hours back, then the date: at 02:00 on the 10th the window that is
     * open opened at 06:00 on the 9th, so the answer is the 9th. The same
     * subtraction `hooks/use-manila-clock.ts` makes for the day strip's label,
     * and the same date a night shift's workday carries (decision 54).
     */
    private function openWindow(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subHours(self::DAY_OPENS_AT_HOUR)->startOfDay();
    }

    /**
     * `YYYY-MM`; defaults to the current month. A malformed value falls back
     * to the default rather than filtering by garbage — WorkdayController and
     * LedgerController read theirs the same way.
     */
    private function month(Request $request): CarbonImmutable
    {
        $value = $request->string('month')->trim()->toString();

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return now()->toImmutable()->startOfMonth();
        }

        return CarbonImmutable::parse($value.'-01')->startOfMonth();
    }

    /**
     * The last day of `$month` that has actually happened, and how many days
     * that is. Null and zero for a month that has not started.
     *
     * "So far" is the whole point of the strip: a month still running must be
     * compared over the days it has, not over the days it will have, or the
     * 9th of September reads as a collapse against the whole of August.
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
     * The five headline figures, each against the same days of the month
     * before (§6.3's figure strip).
     *
     * Occurrences are counted here whatever `settings.occurrences` says. That
     * setting decides whether CS Form 48 *prints* the monthly counts
     * (MC 04 s. 1991) — it is a property of the document, not of the month —
     * and "how much lateness is there" is a management question the office
     * asks either way.
     *
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
            // The generated `date` column, so an overnight authority counts
            // once, on the day it was filed for — which is how a DTR reads it.
            'overtime' => Overtime::query()->whereBetween('date', $window)->count(),
        ];
    }

    /**
     * Where the month's ledgers stand, as a partition of the four states a
     * month can be in.
     *
     * `lockable` is not a guess at readiness: it is exactly what
     * `ledgers_lock_complete` permits — no punch of the month is still due —
     * so a ledger this counts is one the lock button will accept right now,
     * and `open` is the remainder that would be refused. `locked` excludes
     * the attested, so the four add up to `total`.
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
     * Night shifts that have not clocked out.
     *
     * The shape of the question is what makes it answerable: an out side with
     * no `actual_at`, **due inside the window that is open now** and belonging
     * to a workday dated before it — which is precisely a shift that began
     * yesterday and should have ended this morning (decision 54 dates a night
     * shift's workday to the day it started). An out still in the future is
     * not late, and one due in an earlier window is history rather than
     * something waiting on anybody.
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
     * What has happened today, in one list ordered by the clock.
     *
     * Four questions rather than one, because they are four different tables
     * and no join makes them one fact: a late arrival and a missed departure
     * are punches, an excused absence is an exemption, and authorised
     * overtime is an authority. Each carries its own `kind` as a
     * `{value, label}` pair so the page holds no vocabulary of its own
     * (.ai/rules/resources.md) and can still colour by the value.
     *
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
                // The frozen name, never the live shifts row (Workday rule 2).
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
     * Tardiness by workgroup over the same days the figure strip covers, so
     * the meter's total and the strip's figure are one number.
     *
     * Grouped by where the person sits **now** — the substantive placement
     * covering today, `parent_id IS NULL`, which is decision 31's first
     * reading and the one every other screen shows. A reassignment does not
     * move the plantilla item, so it must not move the office the lateness is
     * reported against.
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
            // The whole distribution's total, not the head of it: the meter
            // rows are a top slice and the caption must not shrink with them.
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
     * The lane chart's data (§5.22): one lane per shift the roster puts
     * somebody on today, its bars on the 06:00 → 30:00 scale in minutes from
     * 06:00, and how many people are on it.
     *
     * **From the roster, not from the workdays.** The artboard's own caption
     * says the counts come from the resolved shift, and it has to be that
     * way: a workday is written by the deriver after the punches arrive, so
     * on a live morning there is nothing to count yet. This resolves
     * `position = (D − anchor) mod length` in SQL — the same arithmetic
     * `App\Attendance\Cycle` does in PHP, including the double modulo that
     * keeps a date before the anchor inside `0 .. length - 1` — and so
     * answers in one query what `Resolver` answers in one query *per
     * employee*.
     *
     * What it does **not** do is apply the calendar. `Resolver` does not
     * either ("the calendar does not enter here"): a holiday, a suspension or
     * one person's approved leave changes what is expected of the day, and
     * this is the roster's answer before any of that has its say. The lane
     * chart is a picture of the timetable, not of attendance.
     *
     * A shift with no slots — `Off`, and `Remote`, which expects no punches —
     * gets no lane. Nothing about it can be drawn on a time scale, and a
     * bar-less lane would still add its people to whatever covers the minute.
     *
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
            // The join reaches `employees` directly, so neither SoftDeletes
            // nor the exempt flag is applied for us: somebody who has left,
            // and somebody no record is kept for, are not on duty.
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
                // `shifts.color`, 1 to 8, stored and never derived
                // (04-scheduling.md rule 8) — the same index the roster chip
                // and the legend read, so one shift is one colour everywhere.
                'slot' => (int) $row->color,
                'count' => (int) $row->people,
                'bars' => $bars,
            ];
        }

        return $lanes;
    }

    /**
     * A shift's slots as bars, in **minutes from 06:00** rather than
     * percentages: the scale is 1440 minutes wide and the drawing divides,
     * so nothing is rounded on the way across the wire and a component
     * drawing a different width is not stuck with this one's arithmetic.
     *
     * A slot time may exceed 24:00 — `"30:00"` is 06:00 the next morning
     * (04-scheduling.md) — which is exactly why the window is 06:00 to 30:00
     * and why `Minutes::of` and not a clock library converts it. A bar is
     * clamped to the window and dropped when it falls entirely outside, and
     * its own label keeps the wall-clock reading (`22:00 – 06:00`).
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

    /** The first in to the last out of a set of slots, as `22:00 – 06:00`; null for a shift with none. */
    private function hours(mixed $slots): ?string
    {
        if (! is_array($slots) || $slots === []) {
            return null;
        }

        return $this->clock(Minutes::of($slots[array_key_first($slots)]['in']))
            .' – '
            .$this->clock(Minutes::of($slots[array_key_last($slots)]['out']));
    }

    /** Minutes past midnight as a wall clock, wrapping past 24:00: 1800 is `06:00`. */
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

    /** @return array<string, mixed>|null */
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
