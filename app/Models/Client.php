<?php

namespace App\Models;

use Illuminate\Support\Str;
use Laravel\Passport\Client as Base;

class Client extends Base
{
    /**
     * @var string
     */
    protected $table = 'clients';

    public function newUniqueId(): string
    {
        return strtolower((string) Str::ulid());
    }
}
