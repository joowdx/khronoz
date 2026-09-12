<?php

namespace App\Models\Scopes;

use App\Tenancy\Tenant;
use App\Tenancy\TenantNotResolved;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
