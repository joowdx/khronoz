<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * A ledger *view parameter*, never stored (06-attendance.md, Ledger rule 2).
 * The ledger is one row per employee per month; which half of it the operator
 * is looking at is a query, not a column, so there is no CHECK to mirror and
 * no row in EnumCheckContractTest.
 *
 * `First` is days 1 to 15, `Second` is 16 to the end of the month, `Full`
 * is the whole month. CS Form 48 is one renderer of `Full`.
 */
enum Period: string
{
    use HasChoices;

    /** Days 1 to 15 of the ledger month. */
    case First = 'first';

    /** Day 16 to the last day of the ledger month. */
    case Second = 'second';

    /** The whole month. */
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
