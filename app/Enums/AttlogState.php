<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum AttlogState: int
{
    use HasChoices;

    case CheckIn = 0;

    case CheckOut = 1;

    /** Left for a break the agency punches separately. */
    case BreakOut = 2;

    /** Returned from such a break. */
    case BreakIn = 3;

    /** Arrived for authorised overtime, where the device distinguishes it. */
    case OvertimeIn = 4;

    /** Left from authorised overtime. */
    case OvertimeOut = 5;

    public function label(): string
    {
        return match ($this) {
            self::CheckIn => 'Check in',
            self::CheckOut => 'Check out',
            self::BreakOut => 'Break out',
            self::BreakIn => 'Break in',
            self::OvertimeIn => 'Overtime in',
            self::OvertimeOut => 'Overtime out',
        };
    }

    public static function describe(int $state): string
    {
        return self::tryFrom($state)?->label() ?? "State {$state}";
    }
}
