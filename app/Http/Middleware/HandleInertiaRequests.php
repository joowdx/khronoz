<?php

namespace App\Http\Middleware;

use App\Http\Resources\AgencyResource;
use App\Http\Resources\UserResource;
use App\Models\Agency;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
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
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $messages = array_filter([
            'success' => $request->session()->pull('success'),
            'error' => $request->session()->pull('error'),
        ], fn (mixed $value): bool => is_string($value));

        if ($messages !== []) {
            Inertia::flash($messages);
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $request->user() ? UserResource::make($request->user())->resolve() : null,
            ],
            'agency' => fn () => ($agency = $this->tenant->agency()) ? AgencyResource::make($agency)->resolve() : null,
            'agencies' => fn () => $request->user()?->isPlatform() ? AgencyResource::collection(Agency::orderBy('name')->get())->resolve() : [],
        ];
    }
}
