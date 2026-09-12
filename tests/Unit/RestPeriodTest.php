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
    /**
     * @return array<int, array{in: string, out: string}>
     */
    private function day(string $in, string $out): array
    {
        return [['in' => $in, 'out' => $out]];
    }

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

    public function test_a_null_setting_means_the_rule_does_not_bind(): void
    {
        $everyDay = array_fill(0, 7, $this->standard());

        $this->assertTrue(RestPeriod::satisfied($everyDay, null));
    }

    public function test_the_standard_week_satisfies_every_bound(): void
    {
        $week = [...array_fill(0, 5, $this->standard()), $this->off(), $this->off()];

        foreach (range(1, 6) as $after) {
            $this->assertSame($after >= 5, RestPeriod::satisfied($week, $after), "after {$after}");
        }
    }

    public function test_six_days_then_a_rest_day_is_the_boundary(): void
    {
        $week = [...array_fill(0, 6, $this->standard()), $this->off()];

        $this->assertTrue(RestPeriod::satisfied($week, 6));
        $this->assertFalse(RestPeriod::satisfied($week, 5));
    }

    public function test_a_cycle_with_no_rest_at_all_is_refused(): void
    {
        $everyDay = array_fill(0, 7, $this->standard());

        foreach (range(1, 6) as $after) {
            $this->assertFalse(RestPeriod::satisfied($everyDay, $after), "after {$after}");
        }
    }

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

    public function test_the_twenty_four_forty_eight_post_is_compliant(): void
    {
        $cycle = [['slots' => $this->day('08:00', '32:00')], $this->off(), $this->off()];

        $this->assertTrue(RestPeriod::satisfied($cycle, 1));
    }

    public function test_the_twelve_hour_rotation_is_compliant(): void
    {
        $day = ['slots' => $this->day('06:00', '18:00')];
        $night = ['slots' => $this->day('18:00', '30:00')];

        $cycle = [$day, $day, $night, $night, $this->off(), $this->off(), $this->off(), $this->off()];

        $this->assertTrue(RestPeriod::satisfied($cycle, 4));

        $this->assertTrue(RestPeriod::satisfied($cycle, 2));
        $this->assertFalse(RestPeriod::satisfied($cycle, 1));
    }

    public function test_a_long_midday_break_is_not_a_rest_period(): void
    {
        $split = ['slots' => [
            ['in' => '06:00', 'out' => '09:00'],
            ['in' => '18:00', 'out' => '21:00'],
        ]];

        $cycle = array_fill(0, 7, $split);

        $this->assertFalse(RestPeriod::satisfied($cycle, 6));
    }

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

    public function test_a_cycle_with_no_work_is_vacuously_compliant(): void
    {
        $this->assertTrue(RestPeriod::satisfied(array_fill(0, 3, $this->off()), 1));
    }
}
/** @return array */
