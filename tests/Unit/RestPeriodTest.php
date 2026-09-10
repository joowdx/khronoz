<?php

namespace Tests\Unit;

use App\Support\RestPeriod;
use PHPUnit\Framework\TestCase;

/**
 * Art. 91's rest period, against the worked schedules of 04-scheduling.md and
 * the counterexample docs/reference/dole-rules.md section I item 1 names.
 *
 * A unit test and not a feature test: the rule takes plain arrays precisely so
 * it can be exercised against those examples without a database.
 */
class RestPeriodTest extends TestCase
{
    /** @return array<int, array{in: string, out: string}> */
    private function day(string $in, string $out): array
    {
        return [['in' => $in, 'out' => $out]];
    }

    /** The standard 8-5 day of Rule XVII §5, two slots around a meal period. */
    private function standard(): array
    {
        return ['slots' => [
            ['in' => '08:00', 'out' => '12:00'],
            ['in' => '13:00', 'out' => '17:00'],
        ]];
    }

    private function off(): array
    {
        return ['slots' => []];
    }

    /**
     * Null is the civil-service regime, where the rule does not bind at all —
     * 40 hours over 5 days by rule, so the weekly rest day never applies
     * (decision 32). It must not be read as a lenient default, so even a
     * cycle with no rest whatsoever passes.
     */
    public function test_a_null_setting_means_the_rule_does_not_bind(): void
    {
        $everyDay = array_fill(0, 7, $this->standard());

        $this->assertTrue(RestPeriod::satisfied($everyDay, null));
    }

    /** The standard week: five working days then two Off, which is well inside any bound. */
    public function test_the_standard_week_satisfies_every_bound(): void
    {
        $week = [...array_fill(0, 5, $this->standard()), $this->off(), $this->off()];

        foreach (range(1, 6) as $after) {
            $this->assertSame($after >= 5, RestPeriod::satisfied($week, $after), "after {$after}");
        }
    }

    /** Six ordinary days then one Off: lawful at 6, refused at 5, which is the boundary Art. 91 draws. */
    public function test_six_days_then_a_rest_day_is_the_boundary(): void
    {
        $week = [...array_fill(0, 6, $this->standard()), $this->off()];

        $this->assertTrue(RestPeriod::satisfied($week, 6));
        $this->assertFalse(RestPeriod::satisfied($week, 5));
    }

    /**
     * Work every day of the cycle: there is no gap anywhere, and the seam
     * between repetitions gives none either. Refused at every bound, which is
     * the case a run-length check that forgot to wrap would pass.
     */
    public function test_a_cycle_with_no_rest_at_all_is_refused(): void
    {
        $everyDay = array_fill(0, 7, $this->standard());

        foreach (range(1, 6) as $after) {
            $this->assertFalse(RestPeriod::satisfied($everyDay, $after), "after {$after}");
        }
    }

    /**
     * **The counterexample section I item 1 names, and the reason this counts
     * hours rather than Off turns.**
     *
     * Six work days and one Off turn — which a turn-counting check reads as a
     * standard compliant week — where not one gap in the cycle reaches 24
     * hours. Five morning turns 06:00 to 14:00 sit 16 hours apart; the sixth
     * turn runs 12:00 to 08:00 the next day, so it begins 22 hours after the
     * fifth ends and finishes *inside* the Off day; and the following cycle's
     * first turn starts 06:00, 22 hours after that. The Off turn is real and
     * the rest period never happens.
     *
     * This is the whole reason the rule is elapsed minutes and not a count of
     * Off turns, and it is why the sixth turn's `out` of "32:00" matters: a
     * shift that rolls past midnight eats the rest day it appears to be
     * followed by.
     */
    public function test_an_off_turn_does_not_prove_twenty_four_continuous_hours(): void
    {
        $morning = ['slots' => $this->day('06:00', '14:00')];
        $intoTheOffDay = ['slots' => $this->day('12:00', '32:00')];   // 12:00 to 08:00 next day

        $cycle = [...array_fill(0, 5, $morning), $intoTheOffDay, $this->off()];

        // One Off turn per seven, six work days: a turn count passes this at 6.
        $this->assertCount(7, $cycle);
        $this->assertFalse(RestPeriod::satisfied($cycle, 6));

        // Ending the sixth turn at 22:00 the same day instead leaves 32 hours
        // before the next cycle begins, and the same six work days now comply.
        $cycle[5] = ['slots' => $this->day('12:00', '22:00')];

        $this->assertTrue(RestPeriod::satisfied($cycle, 6));
    }

    /**
     * The hospital rotation of 04-scheduling.md: 21 days, five Morning, two
     * Off, five Afternoon, two Off, five Night, two Off. The Night block runs
     * 22:00 to 06:00, so its last turn ends on the first Off day and the seam
     * back to Morning is the tightest gap in the cycle.
     */
    public function test_the_hospital_rotation_is_compliant(): void
    {
        $morning = ['slots' => $this->day('06:00', '14:00')];
        $afternoon = ['slots' => $this->day('14:00', '22:00')];
        $night = ['slots' => $this->day('22:00', '30:00')];

        $cycle = [
            ...array_fill(0, 5, $morning), $this->off(), $this->off(),
            ...array_fill(0, 5, $afternoon), $this->off(), $this->off(),
            ...array_fill(0, 5, $night), $this->off(), $this->off(),
        ];

        $this->assertCount(21, $cycle);
        $this->assertTrue(RestPeriod::satisfied($cycle, 6));
        $this->assertFalse(RestPeriod::satisfied($cycle, 4), 'five consecutive turns per block');
    }

    /**
     * A rotation with no Off turn anywhere: three Afternoon then three Night,
     * and the cycle repeats. MEASURED, and not what it looks like — the
     * afternoon block ends 22:00 and the night block begins 22:00 the next
     * day, which is **exactly** 24 hours and therefore does qualify, Art. 91
     * asking for "not less than" that.
     *
     * So the cycle is compliant at 6 and refused at 5, on a run of six work
     * days broken by that single boundary-exact rest. The seam back into the
     * next repetition gives only 8 hours (night ends 06:00, afternoon starts
     * 14:00), which is what keeps the run at six rather than three.
     *
     * Pinned because the arithmetic is genuinely surprising: a schedule with
     * no rest *day* can still satisfy a rest *period*.
     */
    public function test_a_rotation_with_no_off_turn_can_still_rest_exactly_twenty_four_hours(): void
    {
        $afternoon = ['slots' => $this->day('14:00', '22:00')];
        $night = ['slots' => $this->day('22:00', '30:00')];

        $cycle = [...array_fill(0, 3, $afternoon), ...array_fill(0, 3, $night)];

        $this->assertTrue(RestPeriod::satisfied($cycle, 6));
        $this->assertFalse(RestPeriod::satisfied($cycle, 5));

        // One hour later and the boundary is missed by that hour.
        $cycle[3] = ['slots' => $this->day('21:00', '29:00')];

        $this->assertFalse(RestPeriod::satisfied($cycle, 6));
    }

    /** The 24/48 guard post: one 24-hour duty then two Off. Forty-eight hours of rest. */
    public function test_the_twenty_four_forty_eight_post_is_compliant(): void
    {
        $cycle = [['slots' => $this->day('08:00', '32:00')], $this->off(), $this->off()];

        $this->assertTrue(RestPeriod::satisfied($cycle, 1));
    }

    /**
     * The 12-hour 2-2-4: two Day12, two Night12, four Off. The second Night12
     * ends 06:00 on the first Off day, leaving 78 hours before the cycle
     * repeats.
     */
    public function test_the_twelve_hour_rotation_is_compliant(): void
    {
        $day = ['slots' => $this->day('06:00', '18:00')];
        $night = ['slots' => $this->day('18:00', '30:00')];

        $cycle = [$day, $day, $night, $night, $this->off(), $this->off(), $this->off(), $this->off()];

        $this->assertTrue(RestPeriod::satisfied($cycle, 4));

        // MEASURED, and worth stating because it reads as four duty days: the
        // longest run here is **two**, not four. The second Day12 ends 18:00
        // and the first Night12 starts 18:00 the next day — exactly 24 hours —
        // so the rotation earns a statutory rest between the day block and the
        // night block without an Off turn between them.
        $this->assertTrue(RestPeriod::satisfied($cycle, 2));
        $this->assertFalse(RestPeriod::satisfied($cycle, 1));
    }

    /**
     * A meal period is not a rest period. A split shift with a nine-hour break
     * in the middle is one work day, not two separated by rest, so the run
     * continues through it.
     */
    public function test_a_long_midday_break_is_not_a_rest_period(): void
    {
        $split = ['slots' => [
            ['in' => '06:00', 'out' => '09:00'],
            ['in' => '18:00', 'out' => '21:00'],
        ]];

        $cycle = array_fill(0, 7, $split);

        $this->assertFalse(RestPeriod::satisfied($cycle, 6));
    }

    /**
     * A remote turn is compensable work (OP MC 114) and must not read as rest.
     * The CWW variant of 04-scheduling.md — four long days, one remote, two
     * Off — is compliant at 5 and refused at 4, which is only true if the
     * remote day counts as work.
     */
    public function test_a_remote_turn_counts_as_work_and_not_as_rest(): void
    {
        $long = ['slots' => [
            ['in' => '07:00', 'out' => '12:00'],
            ['in' => '13:00', 'out' => '18:00'],
        ]];
        $remote = ['slots' => [], 'remote' => true];

        $cycle = [$long, $long, $long, $long, $remote, $this->off(), $this->off()];

        $this->assertTrue(RestPeriod::satisfied($cycle, 5));
        $this->assertFalse(RestPeriod::satisfied($cycle, 4));
    }

    /**
     * A work day is its **first `in` to its last `out`**, not one interval per
     * slot, because the gap between two slots of one day is a meal period and
     * never a rest period.
     *
     * The schedule here is constructed rather than realistic, and deliberately
     * so: flipping this verdict needs a day whose *first* slot ends more than
     * 24 hours before the next day's first slot begins, which takes a very
     * short leading slot. Realistic split shifts do not reach it, so the
     * natural test above (a long midday break) passes either way and pins
     * nothing — MEASURED. This one fails the moment the rule reads the first
     * slot's `out` as the day's end.
     *
     * It also has to alternate two different shifts, which is not obvious:
     * within a cycle of one repeated shift the flip cannot happen at all,
     * because a day's first `out` is always later than its own `in`, so the
     * apparent gap can never reach 24 hours. Two shifts are the minimum that
     * can expose it.
     *
     * A half-hour slot at 00:30 then work until 23:00, alternating with a
     * plain 02:00-10:00 day. Every true gap in the cycle is under three hours,
     * so nothing here is a rest period; reading only the first slot's `out`
     * turns each 00:30-to-01:00 morning into 25 hours of apparent freedom and
     * certifies the cycle.
     */
    public function test_a_work_day_runs_from_its_first_in_to_its_last_out(): void
    {
        $frontLoaded = ['slots' => [
            ['in' => '00:30', 'out' => '01:00'],
            ['in' => '20:00', 'out' => '23:00'],
        ]];
        $plain = ['slots' => [['in' => '02:00', 'out' => '10:00']]];

        $cycle = [$frontLoaded, $plain, $frontLoaded, $plain, $frontLoaded, $plain, $frontLoaded];

        $this->assertFalse(RestPeriod::satisfied($cycle, 6));
    }

    /** A cycle of nothing but Off owes no rest and must not divide by zero or report a violation. */
    public function test_a_cycle_with_no_work_is_vacuously_compliant(): void
    {
        $this->assertTrue(RestPeriod::satisfied(array_fill(0, 3, $this->off()), 1));
    }
}
