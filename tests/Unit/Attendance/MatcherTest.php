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

    /**
     * 06-attendance.md, the chain for one day. Standard 8–5, five taps.
     * Decision 67: 19:31 loses the 17:00 out to 17:05 and does not then
     * fill the missed afternoon in. The 07:58 double tap is equidistant,
     * so in keeps the earlier.
     */
    public function test_standard_worked_example_fills_four_punches_and_leaves_the_stray_unused(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('a', '2026-09-08 07:58:00'),
            $this->tap('b', '2026-09-08 07:58:00'),
            $this->tap('c', '2026-09-08 12:03:00'),
            $this->tap('d', '2026-09-08 17:05:00'),
            $this->tap('e', '2026-09-08 19:31:00'),
        ], 0, false);

        $this->assertInstanceOf(Matching::class, $matching);
        $this->assertCount(4, $matching->sides);
        $this->assertCount(4, $matching->punches);
        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', 'a', '2026-09-08 07:58:00', -2);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-08 12:00:00', 'c', '2026-09-08 12:03:00', 3);
        $this->assertPunch($matching->punches[2], 2, 'in', '2026-09-08 13:00:00', null, null, null);
        $this->assertPunch($matching->punches[3], 2, 'out', '2026-09-08 17:00:00', 'd', '2026-09-08 17:05:00', 5);
    }

    /** Nothing was expected, so there are no punches, however many taps there are. */
    public function test_empty_sides_return_no_punches(): void
    {
        $matching = Matcher::match([], [
            $this->tap('a', '2026-09-08 08:00:00'),
        ], 180, false);

        $this->assertInstanceOf(Matching::class, $matching);
        $this->assertSame([], $matching->sides);
        $this->assertSame([], $matching->punches);
    }

    /**
     * Decision 60: a device punch at 07:58:12 is 07:58, and its deviation
     * against an 08:00 in is −2, not −1.8 rounded to −2 by luck.
     */
    public function test_seconds_are_truncated_not_rounded(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('a', '2026-09-08 07:58:12'),
        ], 0, false);

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', 'a', '2026-09-08 07:58:00', -2);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-08 12:00:00', null, null, null);
    }

    /**
     * Duty24, 08:00–32:00, window ±60. The slot accepts the whole duty,
     * and nearest-side (not "morning is in") puts 19:00 on the in and
     * 21:00 on the out — midpoint 20:00 the same calendar day.
     */
    public function test_duty_twenty_four_midpoint_splits_in_from_out(): void
    {
        $sides = $this->sides(['in' => '08:00', 'out' => '32:00', 'window' => [-60, 60]]);

        $matching = Matcher::match($sides, [
            $this->tap('in', '2026-09-08 19:00:00'),
            $this->tap('out', '2026-09-08 21:00:00'),
        ], 0, false);

        $this->assertCount(2, $matching->punches);
        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', 'in', '2026-09-08 19:00:00', 11 * 60);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-09 08:00:00', 'out', '2026-09-08 21:00:00', -11 * 60);
    }

    /**
     * A tap exactly at a slot's midpoint is equidistant from both sides.
     * The earlier side wins: more plausibly a late arrival than an early
     * departure.
     */
    public function test_a_tap_at_the_midpoint_fills_the_in(): void
    {
        $sides = $this->sides(['in' => '08:00', 'out' => '32:00', 'window' => [-60, 60]]);

        $matching = Matcher::match($sides, [
            $this->tap('mid', '2026-09-08 20:00:00'),
        ], 0, false);

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', 'mid', '2026-09-08 20:00:00', 12 * 60);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-09 08:00:00', null, null, null);
    }

    /**
     * Flexitime, arrive 10:30, offset capped at 180. Expected becomes
     * 10:00–14:00 and 15:00–19:00; the slid window must accept 19:00.
     * Matching.sides is those slid sides, not the originals.
     */
    public function test_flexi_slides_first_and_the_slid_window_accepts_the_late_out(): void
    {
        $matching = Matcher::match($this->flexi(), [
            $this->tap('in', '2026-09-08 10:30:00'),
            $this->tap('out', '2026-09-08 19:00:00'),
        ], 180, false);

        $this->assertSame('2026-09-08 10:00:00', $matching->sides[0]['at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-08 14:00:00', $matching->sides[1]['at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-08 15:00:00', $matching->sides[2]['at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-08 19:00:00', $matching->sides[3]['at']->format('Y-m-d H:i:s'));
        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 10:00:00', 'in', '2026-09-08 10:30:00', 30);
        $this->assertPunch($matching->punches[3], 2, 'out', '2026-09-08 19:00:00', 'out', '2026-09-08 19:00:00', 0);
    }

    /**
     * The slide's candidate is computed against the unslid slot 1 window.
     * 06:20 is before Flexi's 06:30 open, so 10:30 defines the offset.
     */
    public function test_the_flexi_slide_uses_the_earliest_tap_inside_the_unslid_slot(): void
    {
        $matching = Matcher::match($this->flexi(), [
            $this->tap('early', '2026-09-08 06:20:00'),
            $this->tap('in', '2026-09-08 10:30:00'),
        ], 180, false);

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 10:00:00', 'in', '2026-09-08 10:30:00', 30);
    }

    /** Arrive 06:30, on the band open: offset 0, expected stays 07:00. */
    public function test_an_early_flexi_arrival_does_not_slide(): void
    {
        $matching = Matcher::match($this->flexi(), [
            $this->tap('in', '2026-09-08 06:30:00'),
        ], 180, false);

        $this->assertSame('2026-09-08 07:00:00', $matching->sides[0]['at']->format('Y-m-d H:i:s'));
        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 07:00:00', 'in', '2026-09-08 06:30:00', -30);
    }

    /**
     * trust: state 0 is check-in, so 12:03 fills the afternoon in rather
     * than the nearer morning out.
     */
    public function test_trust_restricts_candidates_to_the_device_kind(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('c', '2026-09-08 12:03:00', 0),
        ], 0, true);

        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-08 12:00:00', null, null, null);
        $this->assertPunch($matching->punches[2], 2, 'in', '2026-09-08 13:00:00', 'c', '2026-09-08 12:03:00', -57);
    }

    /**
     * trust: state 1 is check-out, so 07:58 fills the morning out rather
     * than the nearer morning in.
     */
    public function test_trust_out_state_fills_the_out_not_the_nearer_in(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('a', '2026-09-08 07:58:00', 1),
        ], 0, true);

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', null, null, null);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-08 12:00:00', 'a', '2026-09-08 07:58:00', -242);
    }

    /**
     * An unknown attlog integer is no hint at all (03-terminals.md rule 6).
     * 12:03 then takes the nearest side, the morning out.
     */
    public function test_an_unknown_state_falls_back_to_nearest_side(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('c', '2026-09-08 12:03:00', 9),
        ], 0, true);

        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-08 12:00:00', 'c', '2026-09-08 12:03:00', 3);
        $this->assertPunch($matching->punches[2], 2, 'in', '2026-09-08 13:00:00', null, null, null);
    }

    /** When the shift has not asked for trust, state is ignored entirely. */
    public function test_untrusted_state_is_ignored(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('c', '2026-09-08 12:03:00', 0),
        ], 0, false);

        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-08 12:00:00', 'c', '2026-09-08 12:03:00', 3);
        $this->assertPunch($matching->punches[2], 2, 'in', '2026-09-08 13:00:00', null, null, null);
    }

    /**
     * Decision 67: among competitors for one in, nearest fills it. 08:05
     * is 5 minutes late; 07:50 is 10 minutes early. First-wins would keep
     * 07:50.
     */
    public function test_the_nearer_in_fills_the_side_not_the_earlier_one(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('early', '2026-09-08 07:50:00'),
            $this->tap('near', '2026-09-08 08:05:00'),
        ], 0, false);

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', 'near', '2026-09-08 08:05:00', 5);
    }

    /**
     * Decision 67: among competitors for one out, nearest fills it. 17:05
     * is 5 minutes late; 19:31 is 151. Last-wins would keep 19:31, and a
     * cascade would then fill the afternoon in.
     */
    public function test_the_nearer_out_fills_the_side_not_the_later_one(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('near', '2026-09-08 17:05:00'),
            $this->tap('far', '2026-09-08 19:31:00'),
        ], 0, false);

        $this->assertPunch($matching->punches[2], 2, 'in', '2026-09-08 13:00:00', null, null, null);
        $this->assertPunch($matching->punches[3], 2, 'out', '2026-09-08 17:00:00', 'near', '2026-09-08 17:05:00', 5);
    }

    /** Equidistant ins: the kind tie-break keeps the earlier. */
    public function test_equidistant_ins_keep_the_earlier(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('first', '2026-09-08 07:50:00'),
            $this->tap('second', '2026-09-08 08:10:00'),
        ], 0, false);

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 08:00:00', 'first', '2026-09-08 07:50:00', -10);
    }

    /** Equidistant outs: the kind tie-break keeps the later. */
    public function test_equidistant_outs_keep_the_later(): void
    {
        $matching = Matcher::match($this->standard(), [
            $this->tap('first', '2026-09-08 16:50:00'),
            $this->tap('second', '2026-09-08 17:10:00'),
        ], 0, false);

        $this->assertPunch($matching->punches[3], 2, 'out', '2026-09-08 17:00:00', 'second', '2026-09-08 17:10:00', 10);
    }

    /**
     * Hospital Night. `"out": "30:00"` is 06:00 the next day; the workday
     * of the 8th owns that punch.
     */
    public function test_hospital_night_out_lands_on_the_next_day(): void
    {
        $sides = $this->sides(['in' => '22:00', 'out' => '30:00', 'window' => [-120, 120]]);

        $matching = Matcher::match($sides, [
            $this->tap('in', '2026-09-08 22:04:00'),
            $this->tap('out', '2026-09-09 06:02:00'),
        ], 0, false);

        $this->assertPunch($matching->punches[0], 1, 'in', '2026-09-08 22:00:00', 'in', '2026-09-08 22:04:00', 4);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-09 06:00:00', 'out', '2026-09-09 06:02:00', 2);
    }

    /**
     * Times are naive local wall clock. Converting to UTC would move a
     * Manila midnight and break the next-day out.
     */
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
        ], 0, false);

        $this->assertSame('Asia/Manila', $matching->punches[1]['expected_at']->timezoneName);
        $this->assertSame('Asia/Manila', $matching->punches[1]['actual_at']->timezoneName);
        $this->assertPunch($matching->punches[1], 1, 'out', '2026-09-09 06:00:00', 'out', '2026-09-09 06:00:00', 0);
    }
}
