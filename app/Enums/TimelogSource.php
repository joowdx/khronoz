<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum TimelogSource: string
{
    use HasChoices;

    /** Captured by the terminal and carried in by a sync. */
    case Device = 'device';

    /** Entered by a person, because the device missed it. Requires a user. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Device => 'Device',
            self::Manual => 'Entered manually',
        };
    }
}
