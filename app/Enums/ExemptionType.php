<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum ExemptionType: string
{
    use HasChoices;

    /** Any leave of absence: vacation, sick, maternity, paternity, study, terminal. */
    case Leave = 'leave';

    /** Official business — the slip stays `pass`; this is the order. */
    case Business = 'business';

    /** Official travel. Also suppresses excess (JC 2 s. 2015 §7.3). */
    case Travel = 'travel';

    /** Compensatory time off drawn against earned overtime. */
    case Cto = 'cto';

    /** An official-business pass slip: out on the office's errand. */
    case Pass = 'pass';

    /** A personal locator or OB slip: recorded and printed, excuses nothing. */
    case Personal = 'personal';

    /** Sent home, or absent, for a cause the office accepts on the day. */
    case Emergency = 'emergency';

    public function label(): string
    {
        return match ($this) {
            self::Leave => 'Leave',
            self::Business => 'Official business',
            self::Travel => 'Official travel',
            self::Cto => 'Compensatory time off',
            self::Pass => 'Pass slip',
            self::Personal => 'Personal locator slip',
            self::Emergency => 'Emergency',
        };
    }

    public function excuses(): bool
    {
        return $this !== self::Personal;
    }

    public function suppressesExcess(): bool
    {
        return $this === self::Travel;
    }
}
