<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * What the device said the punch *was* — the attlog's `state` column, whose
 * six documented codes are listed under "attlog enumerations" in
 * docs/design/03-terminals.md.
 *
 * **Unlike every other enum here, this one mirrors no CHECK constraint.**
 * `timelogs.state` is an `unsignedTinyInteger` with nothing bounding it, and
 * deliberately so: 03-terminals.md rule 6 keeps `state` and `mode` as the raw
 * device integers, cast with `tryFrom`, so an unfamiliar firmware's code
 * survives the import instead of being refused at the door. `.ai/rules/enums.md`
 * describes the ordinary case — a backed string enum per varchar + CHECK —
 * and this is the deliberate exception. So the enum is a **reading** of the
 * integer, never a constraint on it, and `describe()` below is the whole
 * reason it can be: an unknown code still gets an honest label.
 *
 * The column is also never written back from a case. Nothing in the
 * application sets `state`; the importer copies what the device sent.
 *
 * The labels are the ones the front end used to hold in a TypeScript map,
 * which `.ai/rules/resources.md` forbids and `EnumLabelContractTest` enforces.
 * That map passed the test only because no enum existed for the provider to
 * discover — the test globs `app/Enums/*.php` for a `label()`, so an
 * unmirrored vocabulary was invisible to it rather than permitted by it.
 *
 * Deliberately **no** method mapping a state to an in or an out. The
 * attendance matcher owns that question and answers it from `04-scheduling.md`,
 * because whether a `break out` counts as leaving depends on the shift's
 * `trust` flag and on nothing this enum can see.
 */
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

    /**
     * A label for any integer the column can hold, documented or not.
     *
     * This is the method the resource calls, and it exists because the column
     * is wider than the enum. An undocumented code prints as its own number,
     * which is honest — the alternative is inventing a name for a punch whose
     * meaning nobody here knows, on a record that becomes a payroll deduction.
     */
    public static function describe(int $state): string
    {
        return self::tryFrom($state)?->label() ?? "State {$state}";
    }
}
