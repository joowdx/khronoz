<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreRecoveryCodeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

class RecoveryCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasEnabledTwoFactorAuthentication(), 404);

        return response()->json(['codes' => $request->user()->recoveryCodes()])->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreRecoveryCodeRequest $request, GenerateNewRecoveryCodes $generate): RedirectResponse
    {
        DB::transaction(function () use ($request, $generate): void {
            $user = User::whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($user->hasEnabledTwoFactorAuthentication(), 404);
            $generate($user);
        });
        $request->user()->refresh();

        return back()->with('success', 'Recovery codes replaced. Save the new codes; the previous codes no longer work.');
    }
}
