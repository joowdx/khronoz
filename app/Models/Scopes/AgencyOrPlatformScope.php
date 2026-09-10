<?php

namespace App\Models\Scopes;

use App\Tenancy\Tenant;
use App\Tenancy\TenantNotResolved;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * `agency_id IN (own, platform)` — the one scope in the schema that reads two
 * agencies, and `holidays` is the only table that uses it
 * (07-constraints.md: "Scoping is `agency_id IN (own, platform)` for holidays
 * and `agency_id = own` for everything else"). A national holiday is owned by
 * the platform row and applies to every agency; a local one carries its own.
 *
 * Fails closed exactly as AgencyScope does, and for the same reason: a silent
 * empty result would hide a misconfiguration.
 *
 * The platform row is reached by subquery rather than by Agency::platform(),
 * which would add a SELECT to every read of this table. The subquery is
 * satisfied by the `agencies_platform` partial unique index, and it keeps the
 * whole scope in one statement.
 */
final class AgencyOrPlatformScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(Tenant::class);   // resolved here, never in a constructor: Octane recreates it per request

        if ($tenant->check()) {
            $column = $model->qualifyColumn('agency_id');

            $builder->where(fn (Builder $shared) => $shared
                ->where($column, $tenant->id())
                ->orWhereIn($column, fn (QueryBuilder $platform) => $platform
                    ->select('id')->from('agencies')->where('platform', true)
                )
            );

            return;
        }

        // Real console runs (seeders, maintenance commands) iterate agencies and
        // call Tenant::set() themselves; HTTP and tests must already have one.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        throw new TenantNotResolved($model::class);
    }
}
