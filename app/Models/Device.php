<?php

namespace App\Models;

use Laravel\Passport\DeviceCode as Base;

/**
 * An OAuth device authorization code.
 */
class Device extends Base
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'devices';
}
