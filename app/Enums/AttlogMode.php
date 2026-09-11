<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * How the person identified themselves — the attlog's `mode` column, listed
 * under "attlog enumerations" in docs/design/03-terminals.md.
 *
 * **These labels are provisional.** That document says in as many words that
 * `mode`'s meanings are "to be validated against the device manual", so what
 * is recorded here is the reading the project has been working from and not a
 * verified mapping. The integers are the fact and they are stored untouched;
 * a corrected label changes this file and nothing else, which is most of the
 * reason the vocabulary belongs in one place. `AttlogState` is on firmer
 * ground and its docblock explains the rest of the shared reasoning.
 *
 * Two codes share each of three meanings, which is why the cases are not one
 * per integer: firmware reports fingerprint as either 0 or 1 and card as 2 or
 * 4 depending on the reader, and a face match as 15 or 16. PHP allows only one
 * case per backing value, so the alternates are folded in by `describe()`
 * rather than declared — the enum names the *meanings*, and the column keeps
 * every integer the device sent.
 *
 * Like `AttlogState`, this mirrors no CHECK: `timelogs.mode` is an
 * `unsignedTinyInteger` bounded by nothing, so an unfamiliar firmware's code
 * survives (03-terminals.md rule 6). It is a reading of the integer, never a
 * constraint on it, and nothing in the application ever writes it.
 */
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

    /**
     * A label for any integer the column can hold, documented or not, with the
     * alternate codes folded onto the case that means the same thing.
     *
     * This is the method the resource calls. An undocumented code prints as
     * its own number rather than being guessed at — and given the labels above
     * are unverified, guessing further would compound it.
     */
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
