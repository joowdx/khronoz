<?php

namespace App\Attendance;

use App\Enums\Premium;
use App\Enums\WorkdayStatus;
use App\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * What was expected of one employee on one date, after holidays, work
 * suspensions and exemptions have had their say. No punches, no minute
 * arithmetic — those are the next two chunks.
 *
 * `status` null means the punches decide between absent and present, not
 * that the day is unknown (decision 62). `premium` null is an ordinary
 * day, or a gap in the roster (decision 63).
 */
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
