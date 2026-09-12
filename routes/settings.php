<?php

use App\Http\Controllers\Settings\EmailController;
use App\Http\Controllers\Settings\EmailNotificationController;
use App\Http\Controllers\Settings\PasskeyController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\RecoveryCodeController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TwoFactorConfirmationController;
use App\Http\Controllers\Settings\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::middleware('confirmed')->group(function () {
        Route::get('passkeys/options', [PasskeyController::class, 'create'])->middleware('throttle:10,1')->block()->name('passkeys.options');
        Route::post('passkeys', [PasskeyController::class, 'store'])->middleware('throttle:10,1')->block()->name('passkeys.store');
        Route::patch('passkeys/{credential}', [PasskeyController::class, 'update'])->name('passkeys.update');
        Route::delete('passkeys/{credential}', [PasskeyController::class, 'destroy'])->name('passkeys.destroy');
        Route::get('two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
        Route::post('two-factor', [TwoFactorController::class, 'store'])->name('two-factor.store');
        Route::delete('two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy');
        Route::post('two-factor/confirmation', [TwoFactorConfirmationController::class, 'store'])->middleware('throttle:6,1')->name('two-factor.confirm');
        Route::get('recovery-codes', [RecoveryCodeController::class, 'index'])->name('recovery-codes.index');
        Route::post('recovery-codes', [RecoveryCodeController::class, 'store'])->name('recovery-codes.store');
        Route::get('security', [SecurityController::class, 'edit'])->name('security.edit');
        Route::put('password', [PasswordController::class, 'update'])->name('password.update');
        Route::post('email', [EmailController::class, 'store'])->middleware('throttle:6,1')->name('email.store');
        Route::delete('email', [EmailController::class, 'destroy'])->name('email.destroy');
        Route::post('email/notification', [EmailNotificationController::class, 'store'])->middleware('throttle:6,1')->name('email.notification.store');
    });
});
