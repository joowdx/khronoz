<?php

namespace App\Enums;

/**
 * Mirrors overtimes.mode (varchar) and the overtimes_mode_valid CHECK
 * (mode IN ('pay', 'cto')) — docs/design/07-constraints.md.
 *
 * How the authorised hours are compensated, and payroll's input rather than
 * the deriver's: JC 2 s. 2015 applies 1.25 and 1.5 for pay, or COC 1.0 and
 * 1.5 for compensatory time off, from this value together with whether the
 * date was a scheduled workday (05-calendar.md rule 6). khronoz records the
 * mode and never the peso.
 */
enum OvertimeMode: string
{
    /** Paid at the overtime rate. */
    case Pay = 'pay';

    /** Earned as compensatory overtime credit, drawn later as a `cto` exemption. */
    case Cto = 'cto';

    public function label(): string
    {
        return match ($this) {
            self::Pay => 'Overtime pay',
            self::Cto => 'Compensatory time off',
        };
    }
}
