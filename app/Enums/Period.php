<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum Period: string
{
    use HasChoices;

    /** Days 1 to 15 of the ledger month. */
    case First = 'first';

    /** Day 16 to the last day of the ledger month. */
    case Second = 'second';

    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::First => 'First half',
            self::Second => 'Second half',
            self::Full => 'Whole month',
        };
    }
}
