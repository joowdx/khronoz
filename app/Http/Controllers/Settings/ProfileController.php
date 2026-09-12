<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\Support\Authentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'pendingEmail' => $request->user()->pending_email,
            'emailExpiresAt' => $request->user()->pending_email_expires_at?->toIso8601String(),
            'confirmed' => Authentication::confirmed($request),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $request->user()->update($request->safe()->only('name'));

        return back()->with('success', 'Your profile has been updated.');
    }
}
