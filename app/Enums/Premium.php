<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum Premium: string
{
    use HasChoices;

    /** An Off turn: a rest day under Labor Code Art. 93. */
    case Rest = 'rest';

    /** A special non-working day, including a `local` holiday (decision 49). */
    case Special = 'special';

    /** A regular holiday under Labor Code Art. 94. */
    case Regular = 'regular';

    public function label(): string
    {
        return match ($this) {
            self::Rest => 'Rest day',
            self::Special => 'Special day',
            self::Regular => 'Regular holiday',
        };
    }
}
