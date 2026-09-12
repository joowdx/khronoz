<?php

namespace App\Attendance;

use App\Enums\WorkdayStatus;

final readonly class Derived
{
    public function __construct(
        public WorkdayStatus $status,
        public int $worked,
        public int $credited,
        public int $tardy,
        public int $undertime,
        public int $excess,
        public int $night,
        public int $nightExcess,
    ) {}
}
