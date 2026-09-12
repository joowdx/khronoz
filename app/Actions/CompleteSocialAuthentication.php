<?php

namespace App\Actions;

use App\Models\Identity;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Support\SocialProvider;
use App\Support\SocialState;
use App\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CompleteSocialAuthentication
{
    public function handle(Request $request, string $provider, array $payload): RedirectResponse
    {
        $fallback = $request->user() ? 'settings.connections.index' : 'login';
        try {
            $flow = SocialState::take($request, $provider, $payload['state'] ?? null);
            if (! SocialProvider::available($provider)) {
                return redirect()->route($fallback)->with('error', 'This sign-in provider is currently unavailable. Use your password or a passkey.');
            }
            if (! empty($payload['error'])) {
                return redirect()->route($fallback)->with('error', 'Provider sign-in was cancelled. You can try again.');
            }
            try {
                $remote = app(SocialProvider::class)->user($request, $provider, $payload, $flow['nonce']);
                $subject = $remote->getId();
                if (! is_string($subject) || trim($subject) === '' || strlen($subject) > 255) {
                    throw new \UnexpectedValueException;
                }
                $email = $remote->getEmail();
                $email = is_string($email) && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
            } catch (Throwable) {
                return redirect()->route($fallback)->with('error', 'The provider could not verify this request. Please try again or use your password or a passkey.');
            }
            if ($flow['purpose'] === 'link') {
                try {
                    app(Tenant::class)->within($request->user()->agency, fn () => DB::transaction(function () use ($request, $provider, $subject, $email, $flow) {
                        $owner = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                        if ($owner->password !== $flow['password_hash'] || ! $owner->hasVerifiedEmail() || $flow['expires'] <= now()->timestamp) {
                            throw ValidationException::withMessages(['form' => 'This connection request expired. Please start again.']);
                        }
                        $existing = $owner->identities()->where('provider', $provider)->first();
                        if ($existing && $existing->subject !== $subject) {
                            throw ValidationException::withMessages(['form' => 'Disconnect the current provider account before connecting another.']);
                        }
                        if (! $existing) {
                            $owner->identities()->create(['provider' => $provider, 'subject' => $subject, 'email' => $email]);
                        }
                    }));
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23505') {
                        throw $exception;
                    }
                    throw ValidationException::withMessages(['form' => 'This provider account is already connected to a Khronoz account.']);
                }

                return redirect()->route('settings.connections.index')->with('success', ucfirst($provider).' account connected.');
            }
            // A verified provider subject identifies its owner before tenant context exists. Email is never a login lookup.
            $identity = Identity::withoutGlobalScope(AgencyScope::class)->where('provider', $provider)->where('subject', $subject)->first();
            if (! $identity) {
                return redirect()->route('login')->with('error', 'This provider account is not connected. Sign in with your password or passkey, then connect it in Account settings.');
            }
            $response = app(BeginLogin::class)->handle($request, $identity->user);
            $identity->forceFill(['last_used_at' => now()])->save();

            return $response;
        } catch (ValidationException $exception) {
            return redirect()->route($fallback)->with('error', collect($exception->errors())->flatten()->first());
        }
    }
}
