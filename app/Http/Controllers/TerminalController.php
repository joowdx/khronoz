<?php

namespace App\Http\Controllers;

use App\Enums\TerminalKind;
use App\Enums\TerminalProtocol;
use App\Http\Controllers\Concerns\TranslatesUniqueCollisions;
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

class TerminalController extends Controller
{
    use TranslatesUniqueCollisions;

    public function index(): Response
    {
        Gate::authorize('viewAny', Terminal::class);

        $terminals = Terminal::query()
            ->with('workgroup')
            ->withCount([

                'enrollments as enrolled_count' => fn (Builder $query) => $query->coveringToday(),

                'timelogs',

                'enrollments as enrollments_count',
                'syncs',
            ])
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
        $this->translatingCollisions(['terminals_agency_id_code_unique' => 'code', 'terminals_serial' => 'serial'], fn () => Terminal::create($request->validated()));

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
        $this->translatingCollisions(['terminals_agency_id_code_unique' => 'code', 'terminals_serial' => 'serial'], fn () => $terminal->update($request->validated()));

        return to_route('terminals.index')->with('success', 'Terminal updated.');
    }

    public function destroy(Terminal $terminal): RedirectResponse
    {
        Gate::authorize('delete', $terminal);

        try {
            DB::transaction(fn () => $terminal->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23001') {
                return back()->with('error', $this->restricted($e, $terminal));
            }

            throw $e;
        }

        return to_route('terminals.index')->with('success', 'Terminal removed.');
    }

    private function restricted(QueryException $e, Terminal $terminal): string
    {
        if (str_contains($e->getMessage(), 'enrollments_terminal_id_agency_id_foreign')) {
            return "{$terminal->name} still has enrollments. End them first, or mark the device inactive instead — a terminal that has identified somebody is part of the record.";
        }

        if (str_contains($e->getMessage(), 'syncs_terminal_id_agency_id_foreign')) {
            return "{$terminal->name} has import runs on record and cannot be removed. Mark it inactive instead.";
        }

        return "{$terminal->name} has captured timelogs and cannot be removed. Mark it inactive instead.";
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workgroups(): array
    {
        return WorkgroupResource::collection(
            Workgroup::query()->orderBy('name')->get()
        )->resolve();
    }
}
