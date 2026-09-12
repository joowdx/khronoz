<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum Work: string
{
    use HasChoices;

    /** Ordinary hours: worked, credited, tardy, undertime. */
    case Regular = 'regular';

    /** Excess ∩ Overtime authority (05-calendar.md rule 6). */
    case Overtime = 'overtime';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Overtime => 'Overtime',
        };
    }
}
