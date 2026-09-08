<?php

namespace App\Models;

use Laravel\Passport\RefreshToken as Base;

/**
 * An OAuth refresh token.
 */
class Refresh extends Base
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'refreshes';
}
