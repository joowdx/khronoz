<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum WorkdayStatus: string
{
    use HasChoices;

    /** A scheduled working day that was attended. */
    case Present = 'present';

    /** A scheduled working day with no punch at all. */
    case Absent = 'absent';

    /** An Off turn. Work rendered against it does not move this. */
    case Off = 'off';

    /** A holiday that expects no work. Work rendered does not move this. */
    case Holiday = 'holiday';

    /** A whole-day exemption of a type that excuses. */
    case Exempt = 'exempt';

    /** A whole-day work suspension with no punches. */
    case Suspended = 'suspended';

    /** A remote shift: worked = required, nothing else (OP MC 114). */
    case Remote = 'remote';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Absent => 'Absent',
            self::Off => 'Off',
            self::Holiday => 'Holiday',
            self::Exempt => 'Exempt',
            self::Suspended => 'Suspended',
            self::Remote => 'Remote',
        };
    }

    public function expectsWork(): bool
    {
        return match ($this) {
            self::Off, self::Holiday, self::Suspended => false,
            self::Present, self::Absent, self::Exempt, self::Remote => true,
        };
    }
}
