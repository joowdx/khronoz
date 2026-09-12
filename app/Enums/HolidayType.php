<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

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

    public function expectsWork(): bool
    {
        return $this === self::Working;
    }
}
