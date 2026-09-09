<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * Thrown by BelongsToAgency when a tenant model is created with an explicit
 * agency_id that contradicts the current tenant. Nothing does this today,
 * but a nested write reaching across agencies would otherwise succeed
 * silently — refusing it here is cheaper than a cross-tenant data leak.
 */
final class TenantMismatch extends RuntimeException
{
    public function __construct(string $model, string $tenantId, string $given)
    {
        parent::__construct("Refusing to create {$model} with agency_id {$given}: the current tenant is {$tenantId}.");
    }
}
