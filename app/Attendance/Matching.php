<?php

namespace App\Attendance;

use Carbon\CarbonImmutable;

/**
 * The sides after any flexitime slide, and one punch row per side
 * (04-scheduling.md Matching, 06-attendance.md Punch).
 *
 * No logic: Matcher produces this and the deriver reads it. A missed
 * side keeps timelog_id, actual_at and deviation all null — never a
 * synthesised time (decision 64).
 */
final readonly class Matching
{
    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  list<array{slot: int, kind: string, expected_at: CarbonImmutable, timelog_id: ?string, actual_at: ?CarbonImmutable, deviation: ?int}>  $punches
     */
    public function __construct(
        public array $sides,
        public array $punches,
    ) {}
}
