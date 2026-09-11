<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * Mirrors workdays.premium (nullable varchar) and the workdays_premium_valid
 * CHECK (premium IS NULL OR premium IN ('rest', 'special', 'regular')) —
 * docs/design/07-constraints.md, decision 51. Null is an ordinary day and
 * is not a case here, the same way Sex has no case for "not recorded".
 *
 * The class is frozen at compute time because it is derived from holiday
 * rows and the roster turn, both of which move. A `local` holiday
 * classifies as `Special` (decision 49). Coincident causes take the
 * stronger: Regular over Special over Rest (06-attendance.md daily rule 10).
 *
 * Classification is always written; only `credited` is gated on
 * `settings.premium_hours`. CSC has no premium-regular-hours concept, so
 * a civil-service workday still carries the class and keeps credited at 0.
 */
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
