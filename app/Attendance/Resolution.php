<?php

namespace App\Attendance;

use App\Models\Roster;
use App\Models\Shift;

/**
 * The shift a roster puts on one date: the covering roster, the cycle
 * position, and the turn's shift (04-scheduling.md, Resolution for
 * employee E on date D, steps 1 to 3).
 *
 * Null at the map's value — not a Resolution with a null shift — is how
 * "no roster covers this date" is spelled. That is a different outcome
 * from "not employed", which this value object does not represent.
 */
final readonly class Resolution
{
    public function __construct(
        public Roster $roster,
        public int $position,
        public Shift $shift,
    ) {}
}
