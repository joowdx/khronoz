<?php

namespace App\Http\Controllers;

use App\Enums\TerminalKind;
use App\Enums\TerminalProtocol;
use App\Http\Requests\StoreTerminalRequest;
use App\Http\Requests\UpdateTerminalRequest;
use App\Http\Resources\TerminalResource;
use App\Http\Resources\WorkgroupResource;
use App\Models\Terminal;
use App\Models\Workgroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current tenant's biometric devices. TerminalPolicy is the authority on
 * every action; AgencyScope makes the tenant filter automatic.
 *
 * No `show` route, for the reason `workgroups` has none: a terminal's own page
 * would hold a name, a code and two counts, and the list already shows all
 * four. What a reader actually wants next is the punches — which is
 * `timelogs?terminal=…`, a different resource — so the row links there rather
 * than to a page about the device.
 *
 * No pagination, for the reason the workgroups index has none: an agency has a
 * handful of devices, not a directory of them.
 */
class TerminalController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Terminal::class);

        $terminals = Terminal::query()
            ->with('workgroup')
            ->withCount([
                // How many people this device can currently identify. A
                // terminal with none cannot resolve a single punch it
                // captures, which is the most useful thing this list can say
                // about a newly registered device.
                'enrollments as enrolled_count' => fn (Builder $query) => $query->coveringToday(),
                // Every punch it has ever captured, closed enrollments
                // included. This is also what decides whether Remove can be
                // offered: timelogs_terminal_id_agency_id_foreign RESTRICTs,
                // and destroy() translates that refusal for a stale page.
                'timelogs',
            ])
            // When punches were last successfully brought in. **Not**
            // `synced_at`: decision 40 forbids an import touching that column,
            // because a file is not a device read — so for a file-import
            // terminal it is null forever and a column showing it would read
            // "never" for a device imported this morning. The `syncs` row is
            // where an import's history actually lives, so the answer comes
            // from there, and only from runs that finished.
            ->withMax(
                ['syncs as last_import_at' => fn (Builder $query) => $query->where('status', 'completed')],
                'finished_at',
            )
            ->orderBy('code')
            ->get();

        return Inertia::render('terminals/index', [
            'terminals' => TerminalResource::collection($terminals)->resolve(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Terminal::class);

        return Inertia::render('terminals/create', [
            'workgroups' => fn () => $this->workgroups(),
            'kinds' => TerminalKind::choices(),
            'protocols' => TerminalProtocol::choices(),
        ]);
    }

    public function store(StoreTerminalRequest $request): RedirectResponse
    {
        Terminal::create($request->validated());

        return to_route('terminals.index')->with('success', 'Terminal registered.');
    }

    public function edit(Terminal $terminal): Response
    {
        Gate::authorize('update', $terminal);

        return Inertia::render('terminals/edit', [
            'terminal' => TerminalResource::make($terminal)->resolve(),
            'workgroups' => fn () => $this->workgroups(),
            'kinds' => TerminalKind::choices(),
            'protocols' => TerminalProtocol::choices(),
        ]);
    }

    public function update(UpdateTerminalRequest $request, Terminal $terminal): RedirectResponse
    {
        $terminal->update($request->validated());

        return to_route('terminals.index')->with('success', 'Terminal updated.');
    }

    /**
     * Remove a terminal that has captured nothing.
     *
     * A terminal with punches cannot be deleted — every foreign key into it is
     * RESTRICT, deliberately (decision 41), because the predecessor's
     * `cascadeOnDelete` meant deleting a scanner destroyed every punch it had
     * ever recorded. The index hides the action when `timelogs_count` is
     * non-zero; this translates the refusal anyway, because an index rendered
     * before an import finished is a stale page and the alternative is a 500.
     *
     * `active` is the answer for a device that is genuinely out of service:
     * the row stays, the punches stay, and the switch says it is no longer in
     * use.
     */
    public function destroy(Terminal $terminal): RedirectResponse
    {
        Gate::authorize('delete', $terminal);

        try {
            // Its own transaction, so the refusal is recoverable rather than
            // leaving the surrounding one aborted — the same shape
            // WorkgroupController::destroy uses for the same RESTRICT.
            DB::transaction(fn () => $terminal->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23001') {
                return back()->with('error', 'This terminal has captured timelogs and cannot be removed. Mark it inactive instead.');
            }

            throw $e;
        }

        return to_route('terminals.index')->with('success', 'Terminal removed.');
    }

    /**
     * Where a terminal may be stationed — this tenant's workgroups, flat, for
     * the combobox. A closure prop so a partial reload never re-queries it
     * (.ai/rules/pages.md).
     *
     * @return array<int, array<string, mixed>>
     */
    private function workgroups(): array
    {
        return WorkgroupResource::collection(
            Workgroup::query()->orderBy('name')->get()
        )->resolve();
    }
}
