<?php

namespace App\Attendance;

use Carbon\CarbonImmutable;

final readonly class Matching
{
    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  list<array{slot: int, kind: string, expected_at: ?CarbonImmutable, timelog_id: ?string, actual_at: ?CarbonImmutable, deviation: ?int}>  $punches
     */
    public function __construct(
        public array $sides,
        public array $punches,
    ) {}
}
