<?php

namespace App\Http\Controllers\Settings;

use App\Actions\ConfirmEmailChange;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __invoke(Request $request, string $token, ConfirmEmailChange $confirm): RedirectResponse
    {
        $confirm->handle($request->user(), $token);

        return redirect()->route('settings.profile.edit')->with('success', 'Your login email has been changed.');
    }
}
