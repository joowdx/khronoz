<?php

namespace App\Attendance;

use App\Models\Workday;
use Illuminate\Support\Collection;

/**
 * One ledger read: the workdays of a period, their minute totals, the
 * monthly occurrence counts, and the compensable overtime
 * (06-attendance.md Ledger rules 1–2). No logic: Ledger::view()
 * produces this and nothing stores it.
 */
final readonly class LedgerView
{
    /**
     * @param  Collection<int, Workday>  $workdays
     */
    public function __construct(
        public Collection $workdays,
        public int $worked,
        public int $credited,
        public int $tardy,
        public int $undertime,
        public int $excess,
        public int $night,
        public int $nightExcess,
        public int $overtime,
        public int $tardyOccurrences,
        public int $undertimeOccurrences,
        public int $absences,
    ) {}
}
