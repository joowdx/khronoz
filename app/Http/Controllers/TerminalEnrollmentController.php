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

class TerminalEnrollmentController extends Controller
{
    public function index(Terminal $terminal): Response
    {
        Gate::authorize('view', $terminal);

        $enrollments = $terminal->enrollments()
            ->with('employee')

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

    public function store(StoreEnrollmentRequest $request, Terminal $terminal): RedirectResponse
    {
        $before = $this->attributions($terminal, $request->string('uid')->toString());

        try {

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
     * @return array<int, array<string, mixed>>
     */
    private function employees(): array
    {
        return EmployeeResource::collection(
            Employee::query()->orderBy('last_name')->orderBy('first_name')->get()
        )->resolve();
    }
}
