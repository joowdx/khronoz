<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DestroyTwoFactorRequest;
use App\Http\Requests\Settings\StoreTwoFactorRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;

class TwoFactorController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->two_factor_secret && ! $user->hasEnabledTwoFactorAuthentication(), 404);

        return response()->json(['secret' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret), 'qr' => $user->twoFactorQrCodeSvg()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreTwoFactorRequest $request, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        DB::transaction(function () use ($request, $enable): void {
            $enable(User::whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail());
        });
        $request->user()->refresh();

        return back()->with('success', 'Scan the QR code and enter a code to finish setup.');
    }

    public function destroy(DestroyTwoFactorRequest $request, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        DB::transaction(function () use ($request, $disable): void {
            $disable(User::whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail());
        });
        $request->user()->refresh();

        return back()->with('success', 'Two-factor authentication has been removed.');
    }
}
