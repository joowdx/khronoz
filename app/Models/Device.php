<?php

namespace App\Models;

use Laravel\Passport\DeviceCode as Base;

class Device extends Base
{
    /**
     * @var string
     */
    protected $table = 'devices';
}
