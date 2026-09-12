<?php

namespace App\Attendance;

use App\Enums\Premium;
use App\Enums\WorkdayStatus;
use App\Models\Shift;
use Carbon\CarbonImmutable;

final readonly class Day
{
    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $excused
     */
    public function __construct(
        public CarbonImmutable $date,
        public ?Shift $shift,
        public array $sides,
        public ?WorkdayStatus $status,
        public ?Premium $premium,
        public array $excused,
        public bool $travel,
        public ?string $exemptionId,
        public string $nightFrom,
    ) {}
}
