<?php

use App\Http\Middleware\EnsureAgency;
use App\Http\Middleware\EnsurePlatform;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetTenant::class,
            HandleInertiaRequests::class,
        ]);

        // Resolve the tenant before route bindings.
        $middleware->prependToPriorityList(SubstituteBindings::class, SetTenant::class);

        $middleware->alias([
            'agency' => EnsureAgency::class,
            'platform' => EnsurePlatform::class,
        ]);

        // Avoid resolving a missing login route while handling API guests.
        $middleware->redirectGuestsTo(
            fn () => Route::has('login') ? route('login') : null,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
