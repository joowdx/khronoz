<?php

namespace App\Enums;

/**
 * Mirrors exemptions.type (varchar) and the exemptions_type_valid CHECK
 * (docs/design/07-constraints.md), settled as decision 19.
 *
 * Two behavioural properties live here, and they are **separate**. `excuses()`
 * is whether the covered window stops counting as tardiness, undertime or
 * absence — false only for `Personal`. `suppressesExcess()` is whether work
 * beyond the shift stops accruing as excess — true only for `Travel`
 * (CSC-DBM JC 2 s. 2015 §7.3). Folding the second into the first would either
 * make travel excuse nothing or make every leave suppress overtime, and both
 * are wrong.
 */
enum ExemptionType: string
{
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

    /**
     * Whether the covered window is excused: not tardy, not undertime, not
     * absent. **False only for `Personal`** (decision 19,
     * 05-calendar.md rule 7) — a personal pass slip, also called a locator or
     * OB slip, leaves the day's minutes exactly as the punches make them and
     * the time is charged to leave (Omnibus Rules on Leave §34).
     *
     * `Exemption::excused()` is the name the design and the deriver use; it
     * delegates here, so the truth table sits with the cases.
     */
    public function excuses(): bool
    {
        return $this !== self::Personal;
    }

    /**
     * Whether work outside the shift stops accruing as excess. **True only
     * for `Travel`** (JC 2 s. 2015 §7.3): a day spent travelling on the
     * office's business does not generate overtime from the hours the journey
     * happens to occupy.
     *
     * Deliberately not part of excuses(). A travelling employee is both
     * excused *and* excess-suppressed; everyone else on leave is excused
     * only.
     */
    public function suppressesExcess(): bool
    {
        return $this === self::Travel;
    }
}
