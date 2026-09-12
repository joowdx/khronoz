<?php

namespace App\Attendance;

use App\Models\Workday;
use Illuminate\Support\Collection;

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
