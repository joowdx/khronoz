<?php

namespace App\Models;

use Laravel\Passport\Token as Base;

/**
 * An OAuth access token. The key is the JWT id minted by the authorization
 * server, not a ULID, so no key concern is applied here.
 */
class Token extends Base
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'tokens';
}
