<?php

namespace App\Attendance;

use App\Enums\WorkdayStatus;

/**
 * The status and seven minute columns a workday stores
 * (06-attendance.md daily rules 1 to 10). No logic: Deriver produces
 * this and the orchestrator writes it.
 */
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
