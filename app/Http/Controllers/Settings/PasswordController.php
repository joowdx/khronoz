<?php

namespace App\Http\Controllers\Settings;

use App\Actions\ChangePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class PasswordController extends Controller
{
    public function update(UpdatePasswordRequest $request, ChangePassword $change): RedirectResponse
    {
        $user = $change->handle($request->user(), $request->validated('password'), $request->session()->getId());
        Auth::guard('web')->setUser($user);
        $request->session()->regenerate();

        return redirect()->route('settings.security.edit')->with('success', 'Password updated. Other sessions have been signed out.');
    }
}
