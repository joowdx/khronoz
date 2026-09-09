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
    public function __construct(private Tenant $tenant) {}

    /**
     * List the current tenant's users. Always through the tenant's own
     * agency relation, never User::query() — User carries no tenant scope
     * (a platform user working inside an entered agency must still see
     * themselves in it), so a bare query would list every user in every
     * agency instead of just this one.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $search = $request->string('search', '')->toString();

        $users = $this->tenant->agency()->users()
            ->when($search !== '', fn ($query) => $query->where(
                fn ($query) => $query->whereLike('name', "%{$search}%")->orWhereLike('email', "%{$search}%")
            ))
            ->orderBy('name')
            ->get();

        return Inertia::render('users/index', [
            'users' => UserResource::collection($users)->resolve(),
            'filters' => ['search' => $search],
            'permissions_total' => count(Permission::cases()),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('users/create', [
            'presets' => $this->presets(),
            'permissions' => $this->permissions(),
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
            'permissions' => $this->permissions(),
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

    /**
     * @return array<int, array{value: string, label: string, group: string}>
     */
    private function permissions(): array
    {
        return collect(Permission::cases())->map(fn (Permission $permission) => [
            'value' => $permission->value,
            'label' => $permission->label(),
            'group' => $permission->group(),
        ])->all();
    }
}
