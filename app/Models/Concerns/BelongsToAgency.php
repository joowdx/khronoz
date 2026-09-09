<?php

namespace App\Models\Concerns;

use App\Models\Agency;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\NotPlatformScope;
use App\Tenancy\Tenant;
use App\Tenancy\TenantMismatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every tenant model except User and Agency uses this: a global AgencyScope
 * keyed to the current Tenant, plus agency_id filled from the tenant when a
 * new row is created. User is excluded because authentication must resolve
 * it before any tenant exists; Agency is excluded because it is the tenant.
 */
trait BelongsToAgency
{
    public static function bootBelongsToAgency(): void
    {
        static::addGlobalScope(new AgencyScope);

        static::creating(function (Model $model): void {
            $tenant = app(Tenant::class);

            // An explicit agency_id is trusted as-is when no tenant is set
            // (seeders and maintenance commands that iterate agencies
            // legitimately set it themselves), but once a tenant IS set, an
            // explicit value that disagrees with it is refused rather than
            // silently written — see App\Tenancy\TenantMismatch.
            if ($model->agency_id !== null && $tenant->check() && $model->agency_id !== $tenant->id()) {
                throw new TenantMismatch($model::class, $tenant->id(), $model->agency_id);
            }

            $model->agency_id ??= $tenant->id();
        });
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class)->withoutGlobalScope(NotPlatformScope::class);
    }
}
