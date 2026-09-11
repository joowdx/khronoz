<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * Mirrors workdays.status (varchar) and the workdays_status_valid CHECK
 * (status IN ('present', 'absent', 'off', 'holiday', 'exempt', 'suspended',
 * 'remote')) — docs/design/07-constraints.md. The table lands two chunks
 * from now; the vocabulary is here first so the deriver has names.
 *
 * **Status describes the expectation, not the attendance** (06-attendance.md
 * daily rule 1): a worked Off turn stays `Off` and a worked non-working
 * holiday stays `Holiday`. What records the work is `credited`, `excess` and
 * `premium` (daily rule 10, decision 51).
 */
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

    /**
     * Whether this was a **work day** — a day something was required of the
     * employee, whether or not they turned up.
     *
     * False for the three days nothing was required on: an `Off` turn, a
     * holiday that expects no work, and a suspended day. `Exempt` is true,
     * and deliberately: work was expected and excused, which is a different
     * fact from work never having been expected, and the rule that asks this
     * question needs to tell them apart.
     *
     * The question is `dole-rules.md` section F's — an unworked regular
     * holiday's credit turns on "the immediately preceding **work day**" —
     * and it is asked by `Computer::precedingUnexcusedAbsence`, which walks
     * back past every false until it reaches a true. Mirrors
     * `HolidayType::expectsWork()`, which decides the same thing one layer
     * earlier and for one of these three cases (decision 49).
     */
    public function expectsWork(): bool
    {
        return match ($this) {
            self::Off, self::Holiday, self::Suspended => false,
            self::Present, self::Absent, self::Exempt, self::Remote => true,
        };
    }
}
