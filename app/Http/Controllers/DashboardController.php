<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Tenancy\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in landing page. counts.users is always the current tenant's
 * own staff; counts.agencies joins it only when the tenant itself is the
 * platform agency (docs/design/02-access.md rule 3) — a platform user who
 * has entered a specific agency works inside it exactly like its own staff,
 * so that agency's staff count is all it sees.
 */
class DashboardController extends Controller
{
    public function __construct(private Tenant $tenant) {}

    public function __invoke(): Response
    {
        $agency = $this->tenant->agency();

        // Through the tenant's own agency relation, never a bare
        // User::query() — User carries no tenant scope (app/Models/User.php),
        // so a bare query would count every user of every agency instead of
        // just this one.
        $counts = ['users' => $agency->users()->count()];

        if ($agency->platform) {
            $counts['agencies'] = Agency::count();
        }

        return Inertia::render('dashboard', ['counts' => $counts]);
    }
}
