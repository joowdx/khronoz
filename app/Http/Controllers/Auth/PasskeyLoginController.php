<?php

namespace App\Http\Controllers\Auth;

use App\Actions\CompleteLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StorePasskeyLoginRequest;
use App\Support\PasskeyChallenge;
use App\Support\PasskeyVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;

class PasskeyLoginController extends Controller
{
    public function create(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $request->session()->forget('login');

        return PasskeyChallenge::issue($request, 'login', $generate());
    }

    public function store(StorePasskeyLoginRequest $request, PasskeyVerifier $verify, CompleteLogin $complete): JsonResponse
    {
        $passkey = $verify($request->credential(), $request->verificationOptions());
        $response = $complete->handle($request, $passkey->user, $request->remember());

        return response()->json(['redirect' => $response->getTargetUrl()])->header('Cache-Control', 'no-store, private');
    }
}
