<?php

namespace App\Attendance;

use App\Models\Roster;
use App\Models\Shift;

final readonly class Resolution
{
    public function __construct(
        public Roster $roster,
        public int $position,
        public Shift $shift,
    ) {}
}
