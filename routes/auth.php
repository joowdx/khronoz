<?php

use App\Http\Controllers\Auth\AppleRelayController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmationController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\InviteController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasskeyConfirmationController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Settings\EmailVerificationController;
use Illuminate\Support\Facades\Route;

Route::get('auth/google/callback', [SocialController::class, 'callback'])->middleware('throttle:20,1')->block(30)->name('social.google.callback');
Route::get('auth/apple/complete/{relay}', [AppleRelayController::class, 'complete'])->where('relay', '[A-Za-z0-9]{64}')->middleware('throttle:20,1')->block(30)->name('social.apple.complete');
Route::get('auth/social-error', [SocialController::class, 'failure'])->name('social.failure');

Route::middleware('guest')->group(function () {
    Route::get('auth/{provider}/redirect', [SocialController::class, 'redirect'])->whereIn('provider', ['google', 'apple'])->middleware('throttle:10,1')->block()->name('social.redirect');
    Route::get('passkeys/login/options', [PasskeyLoginController::class, 'create'])->middleware('throttle:10,1')->block()->name('passkeys.login.options');
    Route::post('passkeys/login', [PasskeyLoginController::class, 'store'])->middleware('throttle:10,1')->block()->name('passkeys.login.store');
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->block();
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:6,1')->block()->name('two-factor.login.store');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1')->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');

    // Password setup is available only through the signed invitation link.
    Route::get('invite/{user}', [InviteController::class, 'create'])->middleware('signed')->name('invite.accept');
    Route::post('invite/{user}', [InviteController::class, 'store'])->middleware('signed')->name('invite.store');
});

Route::middleware('auth')->group(function () {
    Route::get('passkeys/confirm/options', [PasskeyConfirmationController::class, 'create'])->middleware('throttle:10,1')->block()->name('passkeys.confirm.options');
    Route::post('passkeys/confirm', [PasskeyConfirmationController::class, 'store'])->middleware('throttle:10,1')->block()->name('passkeys.confirm.store');
    Route::get('confirm-password', [ConfirmationController::class, 'create'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmationController::class, 'store'])->middleware('throttle:6,1')->name('password.confirm.store');
    Route::get('settings/email/verify/{token}', EmailVerificationController::class)
        ->middleware(['verified', 'signed', 'throttle:6,1'])->name('settings.email.verify');
    Route::get('verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])->middleware('throttle:6,1')->name('verification.send');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
