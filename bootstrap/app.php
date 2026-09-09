<?php

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

        // Appended middleware would run after SubstituteBindings, so route models
        // would resolve before the tenant exists. The priority list puts SetTenant
        // right before SubstituteBindings, after StartSession and Authenticate.
        $middleware->prependToPriorityList(SubstituteBindings::class, SetTenant::class);

        $middleware->alias(['platform' => EnsurePlatform::class]);

        // The framework default is `fn () => route('login')`, which Authenticate
        // evaluates while constructing the AuthenticationException. With no login
        // route yet that throws RouteNotFoundException from inside the middleware,
        // so guests got a 500 instead of a 401. Returning null lets the handler
        // answer 401 (as JSON for api/*, see withExceptions below); once a `login`
        // route exists, web requests redirect to it again.
        $middleware->redirectGuestsTo(
            fn () => Route::has('login') ? route('login') : null,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
