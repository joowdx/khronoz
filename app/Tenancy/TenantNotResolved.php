<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * Thrown by AgencyScope when a tenant model is queried with no current
 * agency: either SetTenant has not run yet (an HTTP request or test), or a
 * console command forgot to call Tenant::set() before touching the model.
 */
final class TenantNotResolved extends RuntimeException
{
    public function __construct(string $model)
    {
        parent::__construct("No current agency while querying {$model}: SetTenant did not run, or a command forgot Tenant::set().");
    }
}
