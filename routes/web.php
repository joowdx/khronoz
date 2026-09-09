<?php

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

// Placeholder until Task 10 builds the real dashboard; proves SetTenant and
// the shared agency/agencies props end to end for every signed-in user.
Route::middleware(['auth', 'verified'])->group(fn () => Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard'));
