<?php

namespace App\Models\Concerns;

use App\Models\Agency;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\NotPlatformScope;
use App\Tenancy\Tenant;
use App\Tenancy\TenantMismatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;

/**
 * Every tenant model except User and Agency uses this: a global AgencyScope
 * keyed to the current Tenant, plus agency_id filled from the tenant when a
 * new row is created. User is excluded because authentication must resolve
 * it before any tenant exists; Agency is excluded because it is the tenant.
 *
 * Which scope is applied comes from agencyScope() below, so `holidays` can
 * read its own agency *and* the platform one. Only `holidays` may.
 */
trait BelongsToAgency
{
    public static function bootBelongsToAgency(): void
    {
        static::addGlobalScope(static::agencyScope());

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

    /**
     * The tenant scope this model reads under. Overridable for the one table
     * that legitimately reads two agencies: `holidays` returns
     * AgencyOrPlatformScope, because a national holiday is owned by the
     * platform row and applies to everyone (07-constraints.md). Everything
     * else must leave this alone — a model that widens its own scope is a
     * cross-tenant read, and AgencyScope failing closed is what makes the
     * default safe.
     *
     * The variation lives here rather than in a second trait so the
     * `creating` hook above, which is the subtle half, stays written once.
     */
    protected static function agencyScope(): Scope
    {
        return new AgencyScope;
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class)->withoutGlobalScope(NotPlatformScope::class);
    }
}
