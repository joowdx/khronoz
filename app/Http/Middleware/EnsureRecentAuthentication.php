<?php

namespace App\Http\Middleware;

use App\Support\Authentication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRecentAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Authentication::confirmed($request)) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => 'Confirm your identity to continue.'], 423);
            }

            $destination = $request->isMethod('GET') ? $request->getRequestUri() : '/settings/security';

            return redirect()->route('password.confirm', ['return' => $destination]);
        }

        return $next($request);
    }
}
