<?php

namespace App\Http\Middleware;

use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Returns 404 for agency-only resources under the platform tenant. */
final class EnsureAgency
{
    public function __construct(private Tenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->tenant->check() && $this->tenant->id() === $this->tenant->platformId(), 404);

        return $next($request);
    }
}
