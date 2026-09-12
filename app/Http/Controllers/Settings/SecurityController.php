<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\PasskeyResource;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function edit(Request $request, Tenant $tenant): Response
    {
        return Inertia::render('settings/security', ['passkeys' => $tenant->within($request->user()->agency, fn () => PasskeyResource::collection($request->user()->passkeys()->latest()->get())->resolve()), 'twoFactor' => [
            'enabled' => $request->user()->hasEnabledTwoFactorAuthentication(),
            'pending' => $request->user()->two_factor_secret !== null && ! $request->user()->hasEnabledTwoFactorAuthentication(),
        ]]);
    }
}
