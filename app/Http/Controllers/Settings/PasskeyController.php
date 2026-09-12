<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DestroyPasskeyRequest;
use App\Http\Requests\Settings\StorePasskeyRequest;
use App\Http\Requests\Settings\UpdatePasskeyRequest;
use App\Support\PasskeyChallenge;
use App\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;

class PasskeyController extends Controller
{
    public function create(Request $request, GenerateRegistrationOptions $generate, Tenant $tenant): JsonResponse
    {
        return $tenant->within($request->user()->agency, fn () => PasskeyChallenge::issue($request, 'registration', $generate($request->user())));
    }

    public function store(StorePasskeyRequest $request, StorePasskey $store, Tenant $tenant): JsonResponse
    {
        $options = $request->registrationOptions();
        try {
            $passkey = $tenant->within($request->user()->agency, fn () => DB::transaction(fn () => $store($request->user(), $request->validated('name'), $request->credential(), $options)));
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }
            throw InvalidPasskeyException::make('This passkey is already registered.');
        }
        $request->session()->flash('success', 'Passkey added.');

        return response()->json(['id' => $passkey->id, 'name' => $passkey->name])->header('Cache-Control', 'no-store, private');
    }

    public function update(UpdatePasskeyRequest $request, string $credential, Tenant $tenant): RedirectResponse
    {
        $tenant->within($request->user()->agency, fn () => $request->user()->passkeys()->findOrFail($credential)->update($request->safe()->only('name')));

        return back()->with('success', 'Passkey renamed.');
    }

    public function destroy(DestroyPasskeyRequest $request, string $credential, Tenant $tenant): RedirectResponse
    {
        $tenant->within($request->user()->agency, fn () => $request->user()->passkeys()->findOrFail($credential)->delete());

        return back()->with('success', 'Passkey removed. You can still sign in with your password.');
    }
}
