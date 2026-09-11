<?php

namespace App\Http\Controllers;

use App\Http\Requests\VoidTimelogRequest;
use App\Http\Resources\TerminalResource;
use App\Http\Resources\TimelogResource;
use App\Jobs\RecomputeWorkdays;
use App\Models\Terminal;
use App\Models\Timelog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the devices recorded.
 *
 * Read-only apart from voiding, and that is the schema's doing rather than a
 * choice made here: the app role has no DELETE on this table and its UPDATE is
 * revoked down to `(voided_at, reason)` (decision 41). There is no edit page
 * because there is no edit.
 *
 * The filter that matters is **unresolved**. A punch whose uid matched no
 * enrollment on its date arrives attached to nobody and stays visible — it is
 * not an error and is never hidden — so the one question this screen has to
 * answer quickly is "what came in that we cannot attribute", because that is
 * a month of somebody's pay waiting to be noticed.
 */
class TimelogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Terminal::class);

        // Whitelisted against what the tenant actually has, the way
        // AgencyController::index whitelists `sort`: a mangled query string
        // must not leave the list filtered by a value the picker cannot show.
        $terminal = ($id = $request->string('terminal')->trim()->toString()) === ''
            ? null
            : Terminal::find($id);

        $unresolved = $request->boolean('unresolved');
        $voided = $request->boolean('voided');
        $uid = $request->string('uid')->trim()->toString();
        $from = $this->day($request->string('from')->trim()->toString());
        $to = $this->day($request->string('to')->trim()->toString());

        $timelogs = Timelog::query()
            ->with(['terminal', 'employee', 'voider'])
            ->when($terminal !== null, fn (Builder $query) => $query->where('terminal_id', $terminal->id))
            ->when($unresolved, fn (Builder $query) => $query->whereNull('employee_id'))
            // Voided rows are **included by default**, unlike a soft delete:
            // nothing is ever hidden here (03-terminals.md rule 1), and the
            // row says so in words. The filter narrows *to* them.
            ->when($voided, fn (Builder $query) => $query->whereNotNull('voided_at'))
            ->when($uid !== '', fn (Builder $query) => $query->where('uid', $uid))
            ->when($from !== '', fn (Builder $query) => $query->whereDate('time', '>=', $from))
            ->when($to !== '', fn (Builder $query) => $query->whereDate('time', '<=', $to))
            ->orderByDesc('time')
            ->orderBy('uid')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('timelogs/index', [
            'timelogs' => TimelogResource::collection($timelogs->getCollection())->resolve(),
            'pagination' => [
                'from' => $timelogs->firstItem(),
                'to' => $timelogs->lastItem(),
                'total' => $timelogs->total(),
                'previous' => $timelogs->previousPageUrl(),
                'next' => $timelogs->nextPageUrl(),
            ],
            'filters' => [
                'terminal' => $terminal?->id ?? '',
                'unresolved' => $unresolved,
                'voided' => $voided,
                'uid' => $uid,
                'from' => $from,
                'to' => $to,
            ],
            // A closure, so the terminal picker is not re-queried on every
            // keystroke: the front end reloads only the list's own props, and
            // Inertia never invokes a closure a partial reload excluded.
            'terminals' => fn () => TerminalResource::collection(
                Terminal::query()->orderBy('code')->get()
            )->resolve(),
        ]);
    }

    /**
     * Mark a punch bad without removing it.
     *
     * The row stays and stays visible; `voided_at` and `reason` are the only
     * two columns the app role may write, which is what makes "nothing is ever
     * pruned" a property of the database rather than a promise.
     */
    /**
     * A void is final, and `timelogs_void_is_final` says so with a P0001 —
     * translated here rather than surfacing as a 500. Re-voiding used to
     * overwrite the original timestamp, reason and actor, so the audit record
     * erased itself and the second void looked like the only one there had
     * ever been. The row is already struck out; there is nothing to retry.
     */
    public function void(VoidTimelogRequest $request, Timelog $timelog): RedirectResponse
    {
        try {
            DB::transaction(fn () => $timelog->void($request->string('reason')->toString(), $request->user()));
        } catch (QueryException $e) {
            if ($e->getCode() !== 'P0001') {
                throw $e;
            }

            return back()->with('error', 'That punch was already voided. A void is final, and its reason stays as first recorded.');
        }

        RecomputeWorkdays::dispatchFor([[
            'employee_id' => $timelog->employee_id,
            'time' => $timelog->time->toDateTimeString(),
        ]]);

        return back()->with('success', 'Timelog voided.');
    }

    /** A `YYYY-MM-DD` bound, or '' for anything that is not one. */
    private function day(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
