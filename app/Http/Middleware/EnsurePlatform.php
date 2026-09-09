<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Restricts a route to platform users (docs/design/02-access.md rule 3). Aliased as `platform`. */
final class EnsurePlatform
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isPlatform(), 403);

        return $next($request);
    }
}
