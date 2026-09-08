<?php

namespace App\Models;

use Illuminate\Support\Str;
use Laravel\Passport\Client as Base;

/**
 * An OAuth client.
 */
class Client extends Base
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'clients';

    /**
     * Generate a new key for the model.
     *
     * Passport already enables generated string keys through
     * Passport::$clientUuids, and typing its initializer as void means the
     * HasUlids trait cannot be applied on top. Overriding the generator is
     * the whole of what HasUlids would have contributed here.
     */
    public function newUniqueId(): string
    {
        return strtolower((string) Str::ulid());
    }
}
