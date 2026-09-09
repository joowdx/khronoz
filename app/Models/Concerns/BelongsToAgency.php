<?php

namespace App\Models\Concerns;

use App\Models\Agency;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\NotPlatformScope;
use App\Tenancy\Tenant;
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
            $model->agency_id ??= app(Tenant::class)->id();
        });
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class)->withoutGlobalScope(NotPlatformScope::class);
    }
}
