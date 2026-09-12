<?php

namespace Tests\Unit\Attendance;

use App\Attendance\Expectation;
use App\Attendance\Matcher;
use App\Attendance\Matching;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Timelog-to-side matching against 04-scheduling.md and the worked
 * example in 06-attendance.md. A unit test and not a feature test: the
 * rule takes sides and timelogs as plain arrays so those examples run
 * without a database.
 *
 * 8 September 2026 is the date the attendance chain names.
 */
class MatcherTest extends TestCase
{
    private function day(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-08');
    }

    /**
     * @param  array{in: string, out: string, window: array{0: int, 1: int}}  $slot
     * @return list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>
     */
    private function sides(array ...$slots): array
    {
        return Expectation::sides(['slots' => array_values($slots)], $this->day());
    }

    private function standard(): array
    {
        return $this->sides(
            ['in' => '08:00', 'out' => '12:00', 'window' => [-240, 180]],
            ['in' => '13:00', 'out' => '17:00', 'window' => [-120, 300]],
        );
    }

    private function flexi(): array
    {
        return $this->sides(
            ['in' => '07:00', 'out' => '11:00', 'window' => [-30, 240]],
            ['in' => '12:00', 'out' => '16:00', 'window' => [-60, 360]],
        );
    }

    /**
     * @return array{id: string, time: CarbonImmutable, state: int}
     */
    private function tap(string $id, string $at, int $state = 0): array
    {
        return [
            'id' => $id,
            'time' => CarbonImmutable::parse($at),
            'state' => $state,
        ];
    }

    /**
     * @param  array{slot: int, kind: string, expected_at: mixed, timelog_id: ?string, actual_at: mixed, deviation: ?int}  $punch
     */
    /**
     * @param  array<string, mixed>  $punch
     */
    private function assertTransit(array $punch, int $slot, string $kind, string $timelogId, string $actual): void
    {
        $this->assertSame($slot, $punch['slot']);
        $this->assertSame($kind, $punch['kind']);
        $this->assertNull($punch['expected_at']);
        $this->assertNull($punch['deviation']);
        $this->assertSame($timelogId, $punch['timelog_id']);
        $this->assertInstanceOf(CarbonImmutable::class, $punch['actual_at']);
        $this->assertSame($actual, $punch['actual_at']->format('Y-m-d H:i:s'));
    }

    private function assertPunch(
        array $punch,
        int $slot,
        string $kind,
        string $expected,
        ?string $timelogId,
        ?string $actual,
        ?int $deviation,
    ): void {
        $this->assertSame($slot, $punch['slot']);
        $this->assertSame($kind, $punch['kind']);
        $this->assertInstanceOf(CarbonImmutable::class, $punch['expected_at']);
        $this->assertSame($expected, $punch['expected_at']->format('Y-m-d H:i:s'));
        $this->assertSame($timelogId, $punch['timelog_id']);
        $this->assertSame($deviation, $punch['deviation']);

        if ($actual === null) {
            $this->assertSame(null, $punch['actual_at']);

            return;
        }

        $this->assertInstanceOf(CarbonImmutable::class, $punch['actual_at']);
        $this->assertSame($actual, $punch['actual_at']->format('Y-m-d H:i:s'));
    }

    public function test_standard_worked_example_fills_four_punches_and_leaves_the_stray_unused(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('a', '2026-09-08 07:58:00'),
            $this->tap('b', '2026-09-08 07:58:00'),
            $this->tap('c', '2026-09-08 12:03:00'),
            $this->tap('d', '2026-09-08 17:05:00'),
            $this->tap('e', '2026-09-08 19:31:00'),
        ], 0, false, $this->day());

        $this->assertInstanceOf(Matching::class, $matching);
        $this->assertCount(4, $matching->sides);
        $this->assertCount(4, $matching->punches);
        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', 'a', '2026-09-08 07:58:00', -2);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-08 12:00:00', 'c', '2026-09-08 12:03:00', 3);
        $this->assertPunch($matching->punches[2], 2, 'in', '2026-09-08 13:00:00', null, null, null);
        $this->assertPunch($matching->punches[3], 2, 'out', '2026-09-08 17:00:00', 'd', '2026-09-08 17:05:00', 5);
    }

    public function test_empty_sides_pair_the_taps_in_time_order(): void
    {
        $matching = Matcher::match([], [
            $this->tap('a', '2026-09-12 08:00:00'),
            $this->tap('b', '2026-09-12 12:00:00', 1),
            $this->tap('c', '2026-09-12 13:00:00'),
            $this->tap('d', '2026-09-12 17:00:00', 1),
        ], 180, false, CarbonImmutable::parse('2026-09-12'));

        $this->assertInstanceOf(Matching::class, $matching);
        $this->assertSame([], $matching->sides);
        $this->assertCount(4, $matching->punches);
        $this->assertTransit($matching->punches[0], 1, 'in', 'a', '2026-09-12 08:00:00');
        $this->assertTransit($matching->punches[1], 1, 'out', 'b', '2026-09-12 12:00:00');
        $this->assertTransit($matching->punches[2], 2, 'in', 'c', '2026-09-12 13:00:00');
        $this->assertTransit($matching->punches[3], 2, 'out', 'd', '2026-09-12 17:00:00');
    }

    public function test_a_tap_on_another_day_is_no_transit_of_this_one(): void
    {
        $matching = Matcher::match([], [
            $this->tap('a', '2026-09-12 08:00:00'),
            $this->tap('b', '2026-09-12 17:00:00', 1),
            $this->tap('c', '2026-09-14 08:00:00'),
            $this->tap('d', '2026-09-14 17:00:00', 1),
        ], 180, false, CarbonImmutable::parse('2026-09-12'));

        $this->assertCount(2, $matching->punches);
        $this->assertTransit($matching->punches[0], 1, 'in', 'a', '2026-09-12 08:00:00');
        $this->assertTransit($matching->punches[1], 1, 'out', 'b', '2026-09-12 17:00:00');
    }

    public function test_an_expectation_free_day_nobody_attended_records_nothing(): void
    {
        $matching = Matcher::match([], [
            $this->tap('c', '2026-09-14 08:00:00'),
            $this->tap('d', '2026-09-14 17:00:00', 1),
        ], 180, false, CarbonImmutable::parse('2026-09-12'));

        $this->assertSame([], $matching->punches);
    }

    public function test_empty_sides_and_no_taps_return_no_punches(): void
    {
        $matching = Matcher::match([], [], 180, false, $this->day());

        $this->assertSame([], $matching->sides);
        $this->assertSame([], $matching->punches);
    }

    public function test_an_odd_tap_on_an_expectation_free_day_is_a_lone_in(): void
    {
        $matching = Matcher::match([], [
            $this->tap('a', '2026-09-12 08:00:00'),
            $this->tap('b', '2026-09-12 12:00:00', 1),
            $this->tap('c', '2026-09-12 13:00:00'),
        ], 180, false, CarbonImmutable::parse('2026-09-12'));

        $this->assertCount(3, $matching->punches);
        $this->assertTransit($matching->punches[2], 2, 'in', 'c', '2026-09-12 13:00:00');
    }

    public function test_the_state_hint_does_not_override_the_order(): void
    {
        $matching = Matcher::match([], [
            $this->tap('a', '2026-09-12 08:00:00'),
            $this->tap('b', '2026-09-12 17:00:00'),
        ], 180, true, CarbonImmutable::parse('2026-09-12'));

        $this->assertTransit($matching->punches[0], 1, 'in', 'a', '2026-09-12 08:00:00');
        $this->assertTransit($matching->punches[1], 1, 'out', 'b', '2026-09-12 17:00:00');
    }

    public function test_a_transit_truncates_its_seconds(): void
    {
        $matching = Matcher::match([], [
            $this->tap('a', '2026-09-12 08:00:59'),
        ], 180, false, CarbonImmutable::parse('2026-09-12'));

        $this->assertTransit($matching->punches[0], 1, 'in', 'a', '2026-09-12 08:00:00');
    }

    public function test_seconds_are_truncated_not_rounded(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('a', '2026-09-08 07:58:12'),
        ], 0, false, $this->day());

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', 'a', '2026-09-08 07:58:00', -2);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-08 12:00:00', null, null, null);
    }

    public function test_duty_twenty_four_midpoint_splits_in_from_out(): void
    {
        $sides = $this->sides(['in' => '08:00', 'out' => '32:00', 'window' => [-60, 60]]);

        $matching = Matcher::match($sides, [
            $this->tap('in', '2026-09-08 19:00:00'),
            $this->tap('out', '2026-09-08 21:00:00'),
        ], 0, false, $this->day());

        $this->assertCount(2, $matching->punches);
        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', 'in', '2026-09-08 19:00:00', 11 * 60);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-09 08:00:00', 'out', '2026-09-08 21:00:00', -11 * 60);
    }

    public function test_a_tap_at_the_midpoint_fills_the_in(): void
    {
        $sides = $this->sides(['in' => '08:00', 'out' => '32:00', 'window' => [-60, 60]]);

        $matching = Matcher::match($sides, [
            $this->tap('mid', '2026-09-08 20:00:00'),
        ], 0, false, $this->day());

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', 'mid', '2026-09-08 20:00:00', 12 * 60);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-09 08:00:00', null, null, null);
    }

    public function test_flexi_slides_first_and_the_slid_window_accepts_the_late_out(): void
    {
        $matching = Matcher::match($this->flexi(), [
            $this->tap('in', '2026-09-08 10:30:00'),
            $this->tap('out', '2026-09-08 19:00:00'),
        ], 180, false, $this->day());

        $this->assertSame('2026-09-08 10:00:00', $matching->sides[0]['at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-08 14:00:00', $matching->sides[1]['at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-08 15:00:00', $matching->sides[2]['at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-08 19:00:00', $matching->sides[3]['at']->format('Y-m-d H:i:s'));
        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 10:00:00', 'in', '2026-09-08 10:30:00', 30);
        $this->assertPunch($matching->punches[3], 2, 'out', '2026-09-08 19:00:00', 'out', '2026-09-08 19:00:00', 0);
    }

    public function test_the_flexi_slide_uses_the_earliest_tap_inside_the_unslid_slot(): void
    {
        $matching = Matcher::match($this->flexi(), [
            $this->tap('early', '2026-09-08 06:20:00'),
            $this->tap('in', '2026-09-08 10:30:00'),
        ], 180, false, $this->day());

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 10:00:00', 'in', '2026-09-08 10:30:00', 30);
    }

    public function test_an_early_flexi_arrival_does_not_slide(): void
    {
        $matching = Matcher::match($this->flexi(), [
            $this->tap('in', '2026-09-08 06:30:00'),
        ], 180, false, $this->day());

        $this->assertSame('2026-09-08 07:00:00', $matching->sides[0]['at']->format('Y-m-d H:i:s'));
        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 07:00:00', 'in', '2026-09-08 06:30:00', -30);
    }

    public function test_trust_restricts_candidates_to_the_device_kind(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('c', '2026-09-08 12:03:00', 0),
        ], 0, true, $this->day());

        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-08 12:00:00', null, null, null);
        $this->assertPunch($matching->punches[2], 2, 'in', '2026-09-08 13:00:00', 'c', '2026-09-08 12:03:00', -57);
    }

    public function test_trust_out_state_fills_the_out_not_the_nearer_in(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('a', '2026-09-08 07:58:00', 1),
        ], 0, true, $this->day());

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', null, null, null);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-08 12:00:00', 'a', '2026-09-08 07:58:00', -242);
    }

    public function test_an_unknown_state_falls_back_to_nearest_side(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('c', '2026-09-08 12:03:00', 9),
        ], 0, true, $this->day());

        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-08 12:00:00', 'c', '2026-09-08 12:03:00', 3);
        $this->assertPunch($matching->punches[2], 2, 'in', '2026-09-08 13:00:00', null, null, null);
    }

    public function test_untrusted_state_is_ignored(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('c', '2026-09-08 12:03:00', 0),
        ], 0, false, $this->day());

        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-08 12:00:00', 'c', '2026-09-08 12:03:00', 3);
        $this->assertPunch($matching->punches[2], 2, 'in', '2026-09-08 13:00:00', null, null, null);
    }

    public function test_the_nearer_in_fills_the_side_not_the_earlier_one(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('early', '2026-09-08 07:50:00'),
            $this->tap('near', '2026-09-08 08:05:00'),
        ], 0, false, $this->day());

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', 'near', '2026-09-08 08:05:00', 5);
    }

    public function test_the_nearer_out_fills_the_side_not_the_later_one(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('near', '2026-09-08 17:05:00'),
            $this->tap('far', '2026-09-08 19:31:00'),
        ], 0, false, $this->day());

        $this->assertPunch($matching->punches[2], 2, 'in', '2026-09-08 13:00:00', null, null, null);
        $this->assertPunch($matching->punches[3], 2, 'out', '2026-09-08 17:00:00', 'near', '2026-09-08 17:05:00', 5);
    }

    public function test_equidistant_ins_keep_the_earlier(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('first', '2026-09-08 07:50:00'),
            $this->tap('second', '2026-09-08 08:10:00'),
        ], 0, false, $this->day());

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', 'first', '2026-09-08 07:50:00', -10);
    }

    public function test_equidistant_outs_keep_the_later(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('first', '2026-09-08 16:50:00'),
            $this->tap('second', '2026-09-08 17:10:00'),
        ], 0, false, $this->day());

        $this->assertPunch($matching->punches[3], 2, 'out', '2026-09-08 17:00:00', 'second', '2026-09-08 17:10:00', 10);
    }

    public function test_hospital_night_out_lands_on_the_next_day(): void
    {
        $sides = $this->sides(['in' => '22:00', 'out' => '30:00', 'window' => [-120, 120]]);

        $matching = Matcher::match($sides, [
            $this->tap('in', '2026-09-08 22:04:00'),
            $this->tap('out', '2026-09-09 06:02:00'),
        ], 0, false, $this->day());

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 22:00:00', 'in', '2026-09-08 22:04:00', 4);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-09 06:00:00', 'out', '2026-09-09 06:02:00', 2);
    }

    public function test_times_do_not_convert_a_timezone(): void
    {
        $day = CarbonImmutable::parse('2026-09-08', 'Asia/Manila');
        $sides = Expectation::sides([
            'slots' => [
                ['in' => '22:00', 'out' => '30:00', 'window' => [-120, 120]],
            ],
        ], $day);

        $matching = Matcher::match($sides, [
            [
                'id' => 'out',
                'time' => CarbonImmutable::parse('2026-09-09 06:00:00', 'Asia/Manila'),
                'state' => 1,
            ],
        ], 0, false, CarbonImmutable::parse('2026-09-09'));

        $this->assertSame('Asia/Manila', $matching->punches[1]['expected_at']->timezoneName);
        $this->assertSame('Asia/Manila', $matching->punches[1]['actual_at']->timezoneName);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-09 06:00:00', 'out', '2026-09-09 06:00:00', 0);
    }
}
