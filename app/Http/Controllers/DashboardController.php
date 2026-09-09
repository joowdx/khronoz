<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Tenancy\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in landing page.
 *
 * Everything it reports is a fact about the current tenant, gathered here so
 * the page composes rather than queries. Milestone 1 owns exactly two models,
 * `Agency` and `User`, so those are the only things it can honestly count:
 * workdays, ledgers, shifts and timelogs — the figures the artboard draws —
 * arrive with scheduling and attendance (docs/design/08-interface.md §10).
 *
 * Two shapes, by tenant:
 *
 * - An **agency** tenant sees its own people: `users`, how many are `active`
 *   (have signed in and verified), and how many are still `invited`.
 * - The **platform** tenant sees the estate as well: how many `agencies`
 *   exist, how many `agency_users` they hold between them, and how many of
 *   those agencies are still `empty_agencies`. Its own `users` are the
 *   superusers, which is why there is no separate key for them.
 *
 * `agencies` keys off the *tenant's* platform flag, not the user's, so a
 * platform user who has entered an agency works inside it exactly like its own
 * staff (docs/design/02-access.md rule 3).
 *
 * The `counts` prop is therefore
 * `array{users: int, active: int, invited: int, agencies?: int, agency_users?: int, empty_agencies?: int}`,
 * matching the `DashboardCounts` interface in resources/js/pages/dashboard.tsx.
 */
class DashboardController extends Controller
{
    public function __construct(private Tenant $tenant) {}

    public function __invoke(): Response
    {
        $agency = $this->tenant->agency();

        // Always through the tenant's own agency relation, never a bare
        // User::query() — User carries no tenant scope (app/Models/User.php,
        // .ai/rules/models.md), so a bare query would count every user of
        // every agency instead of just this one. Three cheap counts rather
        // than one clever one: a relation cannot be cloned safely (Relation
        // has no __clone, so two clones share one Builder).
        $counts = [
            'users' => $agency->users()->count(),
            'active' => $agency->users()->whereNotNull('email_verified_at')->count(),
            // Accepting an invitation verifies the address on the way through
            // (Auth\InviteController), so "still invited" is exactly "invited
            // and not yet verified".
            'invited' => $agency->users()->whereNotNull('invited_at')->whereNull('email_verified_at')->count(),
        ];

        if ($agency->platform) {
            // One query for all three platform figures. Agency's own
            // NotPlatformScope keeps the platform row out, so these count real
            // agencies, and withCount avoids a query per agency.
            $agencies = Agency::query()->withCount('users')->get();

            $counts += [
                'agencies' => $agencies->count(),
                'agency_users' => (int) $agencies->sum('users_count'),
                'empty_agencies' => $agencies->where('users_count', 0)->count(),
            ];
        }

        return Inertia::render('dashboard', ['counts' => $counts]);
    }
}
