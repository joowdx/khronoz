<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum PunchKind: string
{
    use HasChoices;

    /** The expected start of a slot. */
    case In = 'in';

    /** The expected end of a slot. */
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'In',
            self::Out => 'Out',
        };
    }
}
