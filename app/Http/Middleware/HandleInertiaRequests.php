<?php

namespace App\Http\Middleware;

use App\Http\Resources\AgencyResource;
use App\Http\Resources\UserResource;
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
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $request->user() ? UserResource::make($request->user())->resolve() : null,
            ],
            'agency' => fn () => ($agency = $this->tenant->agency()) ? AgencyResource::make($agency)->resolve() : null,
            'agencies' => fn () => $request->user()?->isPlatform() ? AgencyResource::collection(Agency::orderBy('name')->get())->resolve() : [],
            // index.d.ts declares success/error optional (`success?: string`),
            // not nullable, so an unset key must be absent, not sent as null.
            'flash' => fn () => array_filter([
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ], fn (mixed $value): bool => $value !== null),
        ];
    }
}
