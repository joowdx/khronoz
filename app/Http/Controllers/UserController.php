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

class UserController extends Controller
{
    private const PER_PAGE = 25;

    private const SORTS = ['name', 'access', 'status'];

    public function __construct(private Tenant $tenant) {}

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

        $query = $this->tenant->agency()->users()->getQuery();

        $this->search($query, $filters['search']);
        $this->filterByAccess($query, $filters['access']);
        $this->filterByStatus($query, $filters['status']);
        $this->sort($query, $filters['sort'], $filters['direction']);

        $users = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('users/index', [

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
     * The delete is left plain: a foreign key from signed attestations to users refuses it at the
     * database for anyone who has signed one, so nothing here needs to anticipate that.
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
