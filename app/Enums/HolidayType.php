<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * Mirrors holidays.type (varchar) and the holidays_type_valid CHECK
 * (type IN ('regular', 'special', 'working', 'local')) — docs/design/07-constraints.md.
 *
 * These are **rate** treatments, not scopes: who a holiday applies to is
 * `holidays.agency_id` (the platform row meaning every agency), and a `local`
 * holiday is one carrying its own agency. `Local` is a case here because an
 * LGU holiday's premium is a fourth treatment in Philippine payroll practice,
 * not because the value says anything about reach.
 */
enum HolidayType: string
{
    use HasChoices;

    /** No work expected; worked time is premium-rated (200% in the private sector). */
    case Regular = 'regular';

    /** No work expected, no-work-no-pay; worked time is premium-rated (130%). */
    case Special = 'special';

    /** A declared holiday that keeps the shift: an ordinary working day at ordinary rates. */
    case Working = 'working';

    /** Declared by a local government; carries its own agency, never the platform row. */
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular holiday',
            self::Special => 'Special non-working',
            self::Working => 'Special working',
            self::Local => 'Local holiday',
        };
    }
}
