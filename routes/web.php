<?php

use App\Http\Controllers\Platform\AgencyController;
use App\Http\Controllers\Platform\EnterAgencyController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('welcome', [
    'packages' => [
        'inertiajs/inertia-laravel',
        'laravel/head',
        'laravel/horizon',
        'laravel/octane',
        'laravel/passport',
        'laravel/sanctum',
        'laravel/scout',
        'laravel/socialite',
        'laravel/telescope',
    ],
]))->name('home')
    ->withHead(title: 'Welcome')
    ->metadata(['ssr' => true]);

Route::middleware(['auth', 'verified'])->group(function () {
    // Placeholder until Task 10 builds the real dashboard; proves SetTenant
    // and the shared agency/agencies props end to end for every signed-in user.
    Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');

    // Platform users only (superusers of the one platform = true agency):
    // list, create and edit agencies, and "enter" one to adopt it as the
    // tenant for the rest of the session — see SetTenant.
    Route::middleware('platform')->prefix('platform')->name('platform.')->group(function () {
        Route::resource('agencies', AgencyController::class)->except(['show', 'destroy']);
        Route::post('agencies/{agency}/enter', [EnterAgencyController::class, 'store'])->name('agencies.enter');
        Route::delete('enter', [EnterAgencyController::class, 'destroy'])->name('agencies.leave');
    });
});

require __DIR__.'/auth.php';
