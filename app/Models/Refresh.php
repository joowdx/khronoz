<?php

namespace App\Models;

use Laravel\Passport\RefreshToken as Base;

class Refresh extends Base
{
    /**
     * @var string
     */
    protected $table = 'refreshes';
}
