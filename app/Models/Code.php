<?php

namespace App\Models;

use Laravel\Passport\AuthCode as Base;

/**
 * An OAuth authorization code.
 */
class Code extends Base
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'codes';
}
