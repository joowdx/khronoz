<?php

namespace App\Http\Controllers;

use App\Actions\InviteUser;
use App\Enums\Permission;
use App\Enums\Preset;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage the colleagues of the current tenant. Every action here is reached
 * only by an authenticated, verified user; UserPolicy (via Gate) is the
 * actual authority on whether they may see or change anything below.
 */
class UserController extends Controller
{
    /**
     * A users list is an office's timekeeping staff, not its headcount, so a page
     * holds every row an agency is likely to have and the pager is usually
     * disabled — but the query is paged anyway, because a platform user who
     * enters a large agency must not be handed the whole table.
     */
    private const PER_PAGE = 25;

    /** The sortable columns, each mapped to what it actually orders by. */
    private const SORTS = ['name', 'access', 'status'];

    public function __construct(private Tenant $tenant) {}

    /**
     * List the current tenant's users. Always through the tenant's own
     * agency relation, never User::query() — User carries no tenant scope
     * (authentication must resolve a user before any tenant exists to scope
     * by, see User::agency()), so a bare query would list every user in
     * every agency instead of just this one.
     *
     * Search, both filters and the sort are server side and travel in the
     * query string, so the list a user is looking at is a link they can
     * send to a colleague. The dashboard's "Invitations not yet accepted"
     * row links straight to ?status=invited.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'access' => $this->oneOf($request->string('access')->toString(), array_column($this->accesses(), 'value')),
            'status' => $this->oneOf($request->string('status')->toString(), array_column($this->statuses(), 'value')),
            'sort' => $this->oneOf($request->string('sort')->toString(), self::SORTS, 'name'),
            'direction' => $this->oneOf($request->string('direction')->toString(), ['asc', 'desc'], 'asc'),
        ];

        // getQuery() hands back the relation's own Eloquent builder, already
        // constrained to this agency, so every helper below can be typed
        // against a Builder instead of a HasMany.
        $query = $this->tenant->agency()->users()->getQuery();

        $this->search($query, $filters['search']);
        $this->filterByAccess($query, $filters['access']);
        $this->filterByStatus($query, $filters['status']);
        $this->sort($query, $filters['sort'], $filters['direction']);

        $users = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('users/index', [
            // The row's permission set is a jsonb column on the row itself,
            // so a page of users is one query however many rows it holds.
            'users' => UserResource::collection($users->getCollection())->resolve(),
            'pagination' => [
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
                'total' => $users->total(),
                'previous' => $users->previousPageUrl(),
                'next' => $users->nextPageUrl(),
            ],
            'filters' => $filters,
            'accesses' => $this->accesses(),
            'statuses' => $this->statuses(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('users/create', [
            'presets' => $this->presets(),
        ]);
    }

    public function store(StoreUserRequest $request, InviteUser $invite): RedirectResponse
    {
        $user = $invite->handle($request->validated());

        return redirect()->route('users.index')->with('success', "Invitation sent to {$user->email}.");
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('users/edit', [
            'user' => UserResource::make($user)->resolve(),
            'presets' => $this->presets(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->update($request->validated());

        return redirect()->route('users.index')->with('success', "{$user->name} updated.");
    }

    /**
     * Milestone 7 adds a foreign key from signed attestations to users that
     * will refuse this delete at the database for anyone who has signed one;
     * nothing here needs to anticipate that beyond leaving the delete plain.
     */
    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $user->delete();

        return redirect()->route('users.index')->with('success', "{$user->name} removed.");
    }

    /**
     * The Access filter's own options: one per preset, plus everything that
     * matches none of them. The labels are the preset's, so the filter and
     * the Access column read the same words (UserResource::access()).
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function accesses(): array
    {
        return [
            ['value' => 'any', 'label' => 'Any'],
            ...collect(Preset::cases())
                ->map(fn (Preset $preset) => ['value' => $preset->value, 'label' => $preset->label()])
                ->all(),
            ['value' => 'custom', 'label' => 'Custom'],
        ];
    }

    /**
     * Two states, because two are all this application can produce: an
     * invitation that has not been accepted yet, and an account that has.
     * The artboard also draws "Locked out", which needs a lockout the
     * product does not have — it is left out rather than faked.
     *
     * `email_verified_at` is the whole test, and deliberately the same one
     * UserInviteController::store() uses to refuse a pointless re-send.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function statuses(): array
    {
        return [
            ['value' => 'any', 'label' => 'Any'],
            ['value' => 'active', 'label' => 'Active'],
            ['value' => 'invited', 'label' => 'Invited'],
        ];
    }

    /**
     * One term against both the name and the address, grouped so the `or`
     * cannot escape the relation's own `agency_id` clause. `whereLike`
     * defaults to case-insensitive, which on Postgres is `ilike`.
     */
    private function search(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(fn (Builder $query) => $query
            ->whereLike('name', "%{$search}%")
            ->orWhereLike('email', "%{$search}%"));
    }

    /**
     * A preset filter is an exact-set match, not a containment one: the
     * column already reads "Admin" only when the held set *is* the preset,
     * so the filter has to agree or the two would disagree on the same row.
     * `@>` proves every value of the preset is held and the length proves
     * nothing else is.
     */
    private function filterByAccess(Builder $query, string $access): void
    {
        if ($access === 'any') {
            return;
        }

        if ($access === 'custom') {
            // Everything that is not one of the presets, including a set
            // that holds nothing at all.
            $query->where(function (Builder $query): void {
                foreach (Preset::cases() as $preset) {
                    $query->whereNot(fn (Builder $query) => $this->whereMatchesPreset($query, $preset));
                }
            });

            return;
        }

        $preset = Preset::tryFrom($access);

        if ($preset !== null) {
            $this->whereMatchesPreset($query, $preset);
        }
    }

    private function whereMatchesPreset(Builder $query, Preset $preset): void
    {
        $values = collect($preset->permissions())->map(fn (Permission $permission) => $permission->value)->all();

        $query->whereJsonLength('permissions', count($values))
            ->whereRaw('permissions @> ?::jsonb', [json_encode($values)]);
    }

    private function filterByStatus(Builder $query, string $status): void
    {
        match ($status) {
            'active' => $query->whereNotNull('email_verified_at'),
            'invited' => $query->whereNull('email_verified_at'),
            default => null,
        };
    }

    /**
     * Both derived columns sort by what the reader sees: Access by how many
     * permissions the row holds, Status by whether the invitation is still
     * outstanding (Postgres orders false before true, so ascending puts
     * accepted accounts first). Name is the tiebreak everywhere and `id`
     * follows it, because a page boundary in the middle of two equal rows
     * has to fall in the same place on every request.
     */
    private function sort(Builder $query, string $sort, string $direction): void
    {
        // $direction is one of two literals (index() narrows it through
        // oneOf) and never reaches the query as a bound value, because a
        // direction is not one.
        match ($sort) {
            'access' => $query->orderByRaw('jsonb_array_length(permissions) '.$direction)->orderBy('name'),
            'status' => $query->orderByRaw('(email_verified_at is null) '.$direction)->orderBy('name'),
            default => $query->orderBy('name', $direction),
        };

        $query->orderBy('id');
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function oneOf(string $value, array $allowed, string $fallback = 'any'): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * The invite form's preset bundles. A preset only ever exists in code
     * (docs/design/02-access.md rule 4): the segmented control expands one
     * into a permission list in the browser and it is that list, never the
     * preset's name, that is submitted and stored.
     *
     * @return array<int, array{value: string, label: string, permissions: array<int, string>}>
     */
    private function presets(): array
    {
        return collect(Preset::cases())->map(fn (Preset $preset) => [
            'value' => $preset->value,
            'label' => $preset->label(),
            'permissions' => collect($preset->permissions())->map(fn (Permission $permission) => $permission->value)->all(),
        ])->all();
    }
}
