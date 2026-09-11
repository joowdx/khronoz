<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentPrivilege;
use App\Http\Requests\EndEnrollmentRequest;
use App\Http\Requests\StoreEnrollmentRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\TerminalResource;
use App\Jobs\RecomputeWorkdays;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Terminal;
use App\Models\Timelog;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who a terminal can identify, over time.
 *
 * This is the screen that makes ingestion mean anything: `timelogs_resolve`
 * matches a punch to the one enrollment covering `(terminal, uid, date)`, so
 * without rows here every imported punch lands unresolved and stays there.
 * It is also, in effect, the terminal's own page — `TerminalController` has no
 * `show` because a terminal's facts fit in the list, but its *people* do not.
 *
 * There is no destroy. An enrollment that punches resolve through cannot be
 * deleted (the paired FK RESTRICTs, decision 41), and one that nothing
 * references still should not be: the range is the history. Ending it with
 * `ends` is the operation, and it is the only edit offered here — changing a
 * `uid` or an `employee_id` re-attributes punches (decision 43), which is a
 * heavier act than this row's everyday verb.
 */
class TerminalEnrollmentController extends Controller
{
    public function index(Terminal $terminal): Response
    {
        Gate::authorize('view', $terminal);

        $enrollments = $terminal->enrollments()
            ->with('employee')
            // Current first, then most recent — the question this page is
            // opened with is "who is on this device now", and the history
            // below it is context rather than the subject.
            ->orderByRaw('ends IS NOT NULL')
            ->orderByDesc('starts')
            ->get();

        return Inertia::render('terminals/enrollments/index', [
            'terminal' => TerminalResource::make($terminal)->resolve(),
            'enrollments' => EnrollmentResource::collection($enrollments)->resolve(),
            'employees' => fn () => $this->employees(),
            'privileges' => EnrollmentPrivilege::choices(),
        ]);
    }

    /**
     * Enrol somebody on this device.
     *
     * The two exclusion constraints are left to speak for themselves — a
     * `Rule::unique` cannot express "no overlapping date range", so restating
     * them in the request would be a worse copy that drifts. Both refusals are
     * 23P01 and they mean different things, so the message names which rule
     * was broken rather than saying "conflict".
     */
    public function store(StoreEnrollmentRequest $request, Terminal $terminal): RedirectResponse
    {
        $before = $this->attributions($terminal, $request->string('uid')->toString());

        try {
            // Its own transaction, so a refused insert leaves the surrounding
            // one usable — otherwise the redirect's own queries answer 25P02.
            DB::transaction(fn () => $terminal->enrollments()->create([
                'agency_id' => $terminal->agency_id,
                ...$request->validated(),
            ]));
        } catch (QueryException $e) {
            if ($e->getCode() !== '23P01') {
                throw $e;
            }

            return back()->withInput()->with('error', $this->collision($e, $terminal, $request->validated()));
        }

        $this->recompute($before, $terminal, $request->string('uid')->toString());

        return back()->with('success', 'Enrolled.');
    }

    /**
     * End an enrollment: the person stopped using this device.
     *
     * A conditional UPDATE rather than `$enrollment->update()`, for the
     * reason EmployeeDeploymentController::destroy is one. Re-dating an
     * enrollment whose successor does not overlap violates no constraint, so
     * the predicate is the only guard against a stale form, and a
     * read-then-write leaves exactly the window it closes.
     * `ends IS NOT DISTINCT FROM :expects` and not `=`, because the expected
     * value is normally null and `ends = NULL` is never true.
     *
     * Zero affected rows means "not the row you were looking at any more" —
     * somebody else ended or corrected it — which is reported rather than
     * retried, because the operator needs to see the current state before
     * choosing a date again.
     *
     * **23P01 is caught, not only 23514.** Extending an end date forward over
     * the range of whoever holds that device user id next is an exclusion
     * violation, not a CHECK violation, and rethrowing it returned a 500 on
     * an ordinary correction. Both refusals are now translated by the same
     * `collision()` the enrol path uses.
     */
    public function update(EndEnrollmentRequest $request, Terminal $terminal, Enrollment $enrollment): RedirectResponse
    {
        $before = $this->attributions($terminal, $enrollment->uid);

        try {
            $closed = DB::transaction(fn () => $terminal->enrollments()
                ->whereKey($enrollment->id)
                ->whereRaw('ends IS NOT DISTINCT FROM ?', [$request->date('expects')?->toDateString()])
                ->update(['ends' => $request->validated('ends')]));
        } catch (QueryException $e) {
            return match ($e->getCode()) {
                '23514' => back()->with('error', 'An enrollment cannot end before it starts.'),
                '23P01' => back()->with('error', $this->overlap($e, $terminal, $enrollment)),
                default => throw $e,
            };
        }

        if ($closed === 0) {
            return back()->with('error', 'That enrollment changed while you were looking at it. Reload and try again.');
        }

        $this->recompute($before, $terminal, $enrollment->uid);

        return back()->with('success', 'Enrollment ended.');
    }

    /**
     * Every (employee, day) an enrollment change moves a punch between
     * (Workday rule 3, decision 86).
     *
     * `enrollments_reresolve` re-attributes existing timelogs whenever an
     * enrollment appears or moves, and it does it in SQL where no job sees
     * it happen. That is not a calendar event and does not go through
     * `FanOutRecompute`: a punch has changed hands, so the days on both
     * sides of the move are days a timelog arrived on and the workday is
     * created if it does not exist yet. The set is read once before the
     * write and once after, and both are queued — the employee losing the
     * punches needs the recompute as much as the one gaining them, and
     * after the write the losing side is already gone from the table.
     *
     * `uid` is what the *device* reported and is never rewritten (decision
     * 42), so the pair identifies the same rows before and after; only the
     * attribution moves. Distinct on the day, not the punch: the span is
     * per-day anyway, and this is a whole device user's history.
     *
     * @return list<object{employee_id: string, time: string}>
     */
    private function attributions(Terminal $terminal, string $uid): array
    {
        return Timelog::query()
            ->where('terminal_id', $terminal->id)
            ->where('uid', $uid)
            ->whereNotNull('employee_id')
            ->toBase()
            ->distinct()
            ->selectRaw('employee_id, time::date::text as time')
            ->get()
            ->all();
    }

    /**
     * @param  list<object{employee_id: string, time: string}>  $before
     */
    private function recompute(array $before, Terminal $terminal, string $uid): void
    {
        RecomputeWorkdays::dispatchFor([...$before, ...$this->attributions($terminal, $uid)]);
    }

    /**
     * A 23P01 on an *end* date, in words.
     *
     * Distinct from `collision()` because the operator's mistake is
     * different: on enrol they chose a device user id that was taken, here
     * they chose an end date that reaches into the range of whoever holds
     * that same id next. Naming the successor's dates is not possible without
     * another query, so the message names the act instead.
     */
    private function overlap(QueryException $e, Terminal $terminal, Enrollment $enrollment): string
    {
        if (str_contains($e->getMessage(), 'enrollments_uid_one_person')) {
            return "That end date reaches into a later enrollment for device user id {$enrollment->uid} on {$terminal->name}. End it no later than the day before that one begins.";
        }

        return 'That end date reaches into a later enrollment for the same person on this terminal.';
    }

    /**
     * Which of the two exclusion constraints refused, in words.
     *
     * They are genuinely different problems and the operator's next move
     * differs: a taken uid means pick another number or end the old
     * enrollment; a person already enrolled means they are already on this
     * device under a different number.
     *
     * @param  array<string, mixed>  $input
     */
    private function collision(QueryException $e, Terminal $terminal, array $input): string
    {
        if (str_contains($e->getMessage(), 'enrollments_uid_one_person')) {
            return "Device user id {$input['uid']} is already held by somebody else on {$terminal->name} over those dates. End that enrollment first, or use a different number.";
        }

        return 'That person is already enrolled on this terminal over those dates, under a different device user id.';
    }

    /**
     * Who can be enrolled: this agency's employees. A closure prop so a
     * partial reload never re-queries it (.ai/rules/pages.md).
     *
     * @return array<int, array<string, mixed>>
     */
    private function employees(): array
    {
        return EmployeeResource::collection(
            Employee::query()->orderBy('last_name')->orderBy('first_name')->get()
        )->resolve();
    }
}
