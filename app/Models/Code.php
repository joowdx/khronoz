<?php

namespace App\Models;

use Laravel\Passport\AuthCode as Base;

class Code extends Base
{
    /**
     * @var string
     */
    protected $table = 'codes';
}
