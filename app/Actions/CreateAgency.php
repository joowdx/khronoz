<?php

namespace App\Actions;

use App\Models\Agency;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

final class CreateAgency
{
    public function __construct(private Tenant $tenant, private CopyDefaults $defaults) {}

    /**
     * @param  array{code: string, name: string}  $attributes
     */
    public function handle(array $attributes): Agency
    {
        return DB::transaction(function () use ($attributes): Agency {
            $agency = Agency::create($attributes);

            $this->tenant->within($agency, fn () => $this->defaults->handle($agency));

            return $agency;
        });
    }
}
