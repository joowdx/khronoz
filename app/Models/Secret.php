<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Sanctum\PersonalAccessToken;

class Secret extends PersonalAccessToken
{
    use HasUlids;

    /**
     * @var string
     */
    protected $table = 'secrets';
}
