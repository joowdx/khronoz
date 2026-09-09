<?php

namespace App\Http\Middleware;

use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to a real agency: 404 when the current tenant is the
 * platform row. Aliased as `agency`, and the mirror image of EnsurePlatform,
 * which restricts a route to platform users.
 *
 * `units` and `employees` both carry an `agency_not_platform` trigger
 * (docs/design/07-constraints.md — nothing operational hangs under the
 * platform agency), so every write on those routes is refused by the database
 * with P0001 when the tenant is the platform row. Nothing above this saw that:
 * SetTenant defaults a platform user's tenant to the platform agency itself,
 * and Gate::before grants a superuser every ability, so the policy layer
 * happily let them reach `/employees/create`, fill the form in and submit it
 * into a 500.
 *
 * 404 rather than 403: the rows these routes are about cannot exist for this
 * tenant at all, so the screens are absent rather than forbidden — which is
 * also what a cross-tenant lookup already answers everywhere else in the app.
 * The sidebar hides the whole Organization group for the same reason
 * (app-sidebar.tsx, OrganizationNavContractTest); that is the courtesy, this
 * is the boundary.
 */
final class EnsureAgency
{
    public function __construct(private Tenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->tenant->check() && $this->tenant->id() === $this->tenant->platformId(), 404);

        return $next($request);
    }
}
