<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

#[Hidden(['credential', 'credential_id'])]
class Passkey extends \Laravel\Passkeys\Passkey
{
    use BelongsToAgency, HasFactory, HasUlids;
}
