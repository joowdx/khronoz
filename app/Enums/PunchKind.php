<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * Mirrors punches.kind (varchar) and the punches_kind_valid CHECK
 * (kind IN ('in', 'out')) — docs/design/07-constraints.md. The table lands
 * two chunks from now; one punch row is one expected slot side.
 *
 * CS Form 48 places a time under AM/PM by clock, but the side of the slot
 * is this value: an 08:00–17:00 shift prints an AM arrival because the punch
 * is `In` at 08:00, not because the column was first (06-attendance.md,
 * CS Form 48; decision 23).
 */
enum PunchKind: string
{
    use HasChoices;

    /** The expected start of a slot. */
    case In = 'in';

    /** The expected end of a slot. */
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'In',
            self::Out => 'Out',
        };
    }
}
