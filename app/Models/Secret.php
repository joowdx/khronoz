<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Sanctum personal access token. Named for what it is rather than for
 * Sanctum's class, so that Passport's access token can keep `Token`.
 */
class Secret extends PersonalAccessToken
{
    use HasUlids;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'secrets';
}
