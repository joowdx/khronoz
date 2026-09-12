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

trait BelongsToAgency
{
    public static function bootBelongsToAgency(): void
    {
        static::addGlobalScope(static::agencyScope());

        static::creating(function (Model $model): void {
            $tenant = app(Tenant::class);

            if ($model->agency_id !== null && $tenant->check() && $model->agency_id !== $tenant->id()) {
                throw new TenantMismatch($model::class, $tenant->id(), $model->agency_id);
            }

            $model->agency_id ??= $tenant->id();
        });
    }

    protected static function agencyScope(): Scope
    {
        return new AgencyScope;
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class)->withoutGlobalScope(NotPlatformScope::class);
    }
}
