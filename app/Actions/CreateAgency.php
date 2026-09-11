<?php

namespace App\Actions;

use App\Models\Agency;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

final class CreateAgency
{
    public function __construct(private Tenant $tenant, private CopyDefaults $defaults) {}

    /**
     * Create a new agency, stocked with the platform's default shifts and
     * schedules (04-scheduling.md rule 7).
     *
     * **One transaction**, because an agency with no shifts is not a usable
     * agency: nobody can be rostered, and the first screen the new
     * administrator opens is empty with no way to tell whether that is the
     * design or a failure. Either both land or neither does.
     *
     * **Inside the new agency's own tenant**, which is the part that is not
     * obvious. The only caller is `Platform\AgencyController`, reached by a
     * platform user — and `SetTenant` defaults a platform user's tenant to the
     * *platform agency itself*. `BelongsToAgency` refuses to write a row whose
     * explicit `agency_id` disagrees with a tenant that is set, so copying
     * without `within()` raises `TenantMismatch` on the first shift. Setting
     * it to the new agency rather than clearing it also means any read inside
     * the copy that forgets to name `agency_id` is confined to the row we are
     * filling, instead of falling back to no tenant at all.
     *
     * The return of `CopyDefaults` is dropped here on purpose: its counts and
     * its `kept` collisions describe copying into an agency that already has
     * rows of its own, and this one was created two statements ago. It is the
     * defaults screen's Copy action that has something to report.
     *
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
