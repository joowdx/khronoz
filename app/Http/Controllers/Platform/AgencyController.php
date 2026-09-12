<?php

namespace App\Http\Controllers\Platform;

use App\Actions\CreateAgency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreAgencyRequest;
use App\Http\Requests\Platform\UpdateAgencyRequest;
use App\Http\Resources\AgencyResource;
use App\Models\Agency;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgencyController extends Controller
{
    private const PER_PAGE = 20;

    private const SORTABLE = ['code' => 'code', 'name' => 'name', 'users' => 'users_count'];

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();
        $sort = $request->string('sort')->toString();
        $sort = array_key_exists($sort, self::SORTABLE) ? $sort : 'name';
        $direction = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';

        $agencies = Agency::query()

            // per row would be one SELECT per agency (N+1).
            ->withCount('users')
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereLike('code', "%{$search}%")->orWhereLike('name', "%{$search}%")
            ))
            ->orderBy(self::SORTABLE[$sort], $direction)
            // Two agencies can share a name; without a tiebreaker the same
            // row could appear on two pages.
            ->orderBy('code')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('platform/agencies/index', [
            'agencies' => AgencyResource::collection($agencies->getCollection())->resolve(),
            'filters' => ['search' => $search, 'sort' => $sort, 'direction' => $direction],
            'pagination' => [
                'from' => $agencies->firstItem(),
                'to' => $agencies->lastItem(),
                'total' => $agencies->total(),
                'previous' => $agencies->previousPageUrl(),
                'next' => $agencies->nextPageUrl(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('platform/agencies/create');
    }

    public function store(StoreAgencyRequest $request, CreateAgency $create): RedirectResponse
    {
        $create->handle($request->validated());

        return redirect()->route('platform.agencies.index')->with('success', 'Agency added');
    }

    public function edit(Agency $agency): Response
    {
        return Inertia::render('platform/agencies/edit', [
            'agency' => AgencyResource::make($agency)->resolve(),
        ]);
    }

    public function update(UpdateAgencyRequest $request, Agency $agency): RedirectResponse
    {
        $agency->update($request->validated());

        return redirect()->route('platform.agencies.index')->with('success', 'Changes saved');
    }
}
