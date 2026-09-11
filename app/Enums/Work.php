<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * A ledger *view parameter*, never stored (06-attendance.md, Ledger rule 2).
 * Orthogonal to `Period`: `$ledger->view(Period::First, Work::Overtime)`
 * is the overtime slice of the first half, and omitting this argument means
 * both. There is no CHECK to mirror and no row in EnumCheckContractTest.
 *
 * Compensable overtime is not a workday number (daily rule 6); this is the
 * ledger view that intersects `excess` with an Overtime authority.
 */
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
