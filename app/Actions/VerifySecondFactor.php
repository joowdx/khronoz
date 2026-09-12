<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

final class VerifySecondFactor
{
    public function __construct(private TwoFactorAuthenticationProvider $provider) {}

    public function handle(User $user, ?string $code, ?string $recovery = null): void
    {
        DB::transaction(function () use ($user, $code, $recovery): void {
            $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if ($owner->hasEnabledTwoFactorAuthentication()) {
                if ($recovery) {
                    $codes = $owner->recoveryCodes();
                    foreach ($codes as $key => $stored) {
                        if (hash_equals($stored, $recovery)) {
                            unset($codes[$key]);
                            $owner->forceFill(['two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(array_values($codes)))])->save();

                            return;
                        }
                    }
                } elseif ($code && $this->provider->verify(Fortify::currentEncrypter()->decrypt($owner->two_factor_secret), $code)) {
                    return;
                }
            }

            throw ValidationException::withMessages([$recovery ? 'recovery_code' : 'code' => 'Invalid or already used code']);
        });
        $user->refresh();
    }
}
