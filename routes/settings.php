<?php

use App\Http\Controllers\Settings\EmailController;
use App\Http\Controllers\Settings\EmailNotificationController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::middleware('confirmed')->group(function () {
        Route::get('security', [SecurityController::class, 'edit'])->name('security.edit');
        Route::put('password', [PasswordController::class, 'update'])->name('password.update');
        Route::post('email', [EmailController::class, 'store'])->middleware('throttle:6,1')->name('email.store');
        Route::delete('email', [EmailController::class, 'destroy'])->name('email.destroy');
        Route::post('email/notification', [EmailNotificationController::class, 'store'])->middleware('throttle:6,1')->name('email.notification.store');
    });
});
