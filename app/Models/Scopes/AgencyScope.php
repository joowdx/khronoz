<?php

namespace App\Models\Scopes;

use App\Tenancy\Tenant;
use App\Tenancy\TenantNotResolved;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class AgencyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(Tenant::class);   // resolved here, never in a constructor: Octane recreates it per request

        if ($tenant->check()) {
            $builder->where($model->qualifyColumn('agency_id'), $tenant->id());

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
