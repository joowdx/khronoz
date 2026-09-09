<?php

namespace App\Http\Middleware;

use App\Models\Agency;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current agency for an authenticated request: a staff user's
 * own agency, or the agency a platform user has "entered" (session, defaults
 * to the platform agency itself). Must run before SubstituteBindings (see
 * bootstrap/app.php) so route model bindings resolve inside the tenant.
 */
final class SetTenant
{
    public function __construct(private Tenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $agency = $user->isPlatform() ? $this->chosen($request) : $user->agency;

            if ($agency) {
                $this->tenant->set($agency);
            }
        }

        return $next($request);
    }

    private function chosen(Request $request): Agency
    {
        $id = $request->session()->get('agency');

        return ($id ? Agency::find($id) : null) ?? Agency::platform();
    }
}
