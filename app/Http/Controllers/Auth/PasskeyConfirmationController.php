<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StorePasskeyConfirmationRequest;
use App\Support\Authentication;
use App\Support\PasskeyChallenge;
use App\Support\PasskeyVerifier;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;

class PasskeyConfirmationController extends Controller
{
    public function create(Request $request, GenerateVerificationOptions $generate, Tenant $tenant): JsonResponse
    {
        return $tenant->within($request->user()->agency, fn () => PasskeyChallenge::issue($request, 'confirmation', $generate($request->user())));
    }

    public function store(StorePasskeyConfirmationRequest $request, PasskeyVerifier $verify): JsonResponse
    {
        $verify($request->credential(), $request->verificationOptions(), $request->user());
        Authentication::confirm($request);

        return response()->json(['redirect' => Authentication::destination($request->validated('destination'))])->header('Cache-Control', 'no-store, private');
    }
}
