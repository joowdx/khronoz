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
