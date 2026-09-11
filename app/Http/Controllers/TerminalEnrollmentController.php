<?php

namespace App\Http\Controllers;

use App\Http\Requests\EndEnrollmentRequest;
use App\Http\Requests\StoreEnrollmentRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\TerminalResource;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Terminal;
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

        return back()->with('success', 'Enrolled.');
    }

    /** End an enrollment: the person stopped using this device. */
    public function update(EndEnrollmentRequest $request, Terminal $terminal, Enrollment $enrollment): RedirectResponse
    {
        try {
            DB::transaction(fn () => $enrollment->update($request->validated()));
        } catch (QueryException $e) {
            if ($e->getCode() !== '23514') {
                throw $e;
            }

            return back()->with('error', 'An enrollment cannot end before it starts.');
        }

        return back()->with('success', 'Enrollment ended.');
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
