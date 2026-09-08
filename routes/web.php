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
