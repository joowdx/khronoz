<?php

namespace App\Actions;

use App\Models\Agency;

final class CreateAgency
{
    /**
     * Create a new agency.
     *
     * A single clean seam: Milestone 3 wraps this in a transaction that also
     * copies the platform agency's default shifts and schedules into the new
     * one, without the controller needing to change.
     *
     * @param  array{code: string, name: string}  $attributes
     */
    public function handle(array $attributes): Agency
    {
        return Agency::create($attributes);
    }
}
