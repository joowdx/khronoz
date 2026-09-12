<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum AttlogMode: int
{
    use HasChoices;

    /** A fingerprint match. Also reported as 1. */
    case Fingerprint = 0;

    /** A card or fob. Also reported as 4. */
    case Card = 2;

    /** A PIN typed at the keypad. */
    case Password = 3;

    /** A face match. Also reported as 16. */
    case Face = 15;

    public function label(): string
    {
        return match ($this) {
            self::Fingerprint => 'Fingerprint',
            self::Card => 'Card',
            self::Password => 'Password',
            self::Face => 'Face',
        };
    }

    public static function describe(int $mode): string
    {
        return match ($mode) {
            1 => self::Fingerprint->label(),
            4 => self::Card->label(),
            16 => self::Face->label(),
            default => self::tryFrom($mode)?->label() ?? "Mode {$mode}",
        };
    }
}
