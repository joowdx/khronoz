<?php

namespace App\Http\Controllers;

use App\Http\Resources\SyncResource;
use App\Http\Resources\TerminalResource;
use App\Models\Sync;
use App\Models\Terminal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every ingestion run, successful or refused.
 *
 * Entirely read-only — the app role has no DELETE on this table (decision 41),
 * because a run record that can be deleted is a run that can be denied.
 *
 * The rows worth finding are the **failed** ones, and not because something
 * went wrong technically: a file naming two device numbers is refused whole as
 * probable tampering (decision 44), and the only lasting trace of that attempt
 * is the row here carrying its reason. Without this screen a refusal is a
 * flash message that the second attempt looks identical to.
 */
class SyncController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Terminal::class);

        $terminal = ($id = $request->string('terminal')->trim()->toString()) === ''
            ? null
            : Terminal::find($id);

        $failed = $request->boolean('failed');

        $syncs = Sync::query()
            ->with('terminal')
            ->when($terminal !== null, fn (Builder $query) => $query->where('terminal_id', $terminal->id))
            ->when($failed, fn (Builder $query) => $query->where('status', 'failed'))
            ->orderByDesc('started_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('syncs/index', [
            'syncs' => SyncResource::collection($syncs->getCollection())->resolve(),
            'pagination' => [
                'from' => $syncs->firstItem(),
                'to' => $syncs->lastItem(),
                'total' => $syncs->total(),
                'previous' => $syncs->previousPageUrl(),
                'next' => $syncs->nextPageUrl(),
            ],
            'filters' => [
                'terminal' => $terminal?->id ?? '',
                'failed' => $failed,
            ],
            'terminals' => fn () => TerminalResource::collection(
                Terminal::query()->orderBy('code')->get()
            )->resolve(),
        ]);
    }
}
