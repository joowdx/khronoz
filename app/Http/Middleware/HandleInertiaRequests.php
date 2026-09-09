<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Http\Resources\AgencyResource;
use App\Models\Agency;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private readonly Tenant $tenant) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * `auth` is shaped by hand rather than an API resource: UserResource does
     * not exist until Task 7, and resources/js/types/index.d.ts's AuthUser
     * expects `permissions` as plain permission values and a `platform` flag
     * that is not a database column, so the model cannot just be dumped.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => ($user = $request->user()) ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'permissions' => $user->permissions->map(fn (Permission $permission) => $permission->value)->all(),
                    'platform' => $user->isPlatform(),
                    'employee_id' => $user->employee_id,
                ] : null,
            ],
            'agency' => fn () => ($agency = $this->tenant->agency()) ? AgencyResource::make($agency)->resolve() : null,
            'agencies' => fn () => $request->user()?->isPlatform() ? AgencyResource::collection(Agency::orderBy('name')->get())->resolve() : [],
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
