<?php

namespace App\Models;

use Laravel\Passport\Token as Base;

class Token extends Base
{
    /**
     * @var string
     */
    protected $table = 'tokens';
}
