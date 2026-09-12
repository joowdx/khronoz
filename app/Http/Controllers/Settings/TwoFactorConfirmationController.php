<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreTwoFactorConfirmationRequest;
use App\Models\User;
use App\Support\Authentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;

class TwoFactorConfirmationController extends Controller
{
    public function store(StoreTwoFactorConfirmationRequest $request, ConfirmTwoFactorAuthentication $confirm): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $confirm): void {
                $user = User::whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
                abort_if($user->hasEnabledTwoFactorAuthentication(), 409);
                $confirm($user, $request->validated('code'));
            });
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['code' => 'Invalid or already used code']);
        }
        $request->user()->refresh();
        Authentication::confirm($request);

        return back()->with('success', 'Two-factor authentication is enabled. Save your recovery codes.');
    }
}
