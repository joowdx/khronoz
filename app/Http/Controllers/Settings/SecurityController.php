<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/security', ['twoFactor' => [
            'enabled' => $request->user()->hasEnabledTwoFactorAuthentication(),
            'pending' => $request->user()->two_factor_secret !== null && ! $request->user()->hasEnabledTwoFactorAuthentication(),
        ]]);
    }
}
