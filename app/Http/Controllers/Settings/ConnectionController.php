<?php

namespace App\Http\Controllers\Settings;

use App\Actions\StartSocialAuthentication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DestroyConnectionRequest;
use App\Http\Requests\Settings\StoreConnectionRequest;
use App\Http\Resources\IdentityResource;
use App\Support\Authentication;
use App\Support\SocialProvider;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse as ProviderRedirect;

class ConnectionController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        return Inertia::render('settings/connections', [
            'connections' => $tenant->within($request->user()->agency, fn () => IdentityResource::collection($request->user()->identities()->orderBy('provider')->get())->resolve()),
            'providers' => SocialProvider::availability(), 'confirmed' => Authentication::confirmed($request),
        ]);
    }

    public function store(StoreConnectionRequest $request, string $provider, StartSocialAuthentication $start): ProviderRedirect|\Illuminate\Http\Response
    {
        $response = $start->handle($request, $provider, 'link');

        return $request->header('X-Inertia') ? Inertia::location($response->getTargetUrl()) : $response;
    }

    public function destroy(DestroyConnectionRequest $request, string $connection, Tenant $tenant): RedirectResponse
    {
        $tenant->within($request->user()->agency, fn () => $request->user()->identities()->findOrFail($connection)->delete());

        return back()->with('success', 'Account disconnected from Khronoz. Provider permissions can be managed directly with Google or Apple.');
    }
}
