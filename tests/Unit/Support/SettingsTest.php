<?php

namespace Tests\Unit\Support;

use App\Enums\MissingSide;
use App\Models\Agency;
use App\Support\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Decision 56: one typed reader for the six keys Milestone 6 reads, so a
 * default lives in one place rather than at every `$agency->settings['…'] ??`
 * call site. The deriver will run from a queued job with no session, which is
 * why this takes an Agency and never `auth()`.
 */
class SettingsTest extends TestCase
{
    public function test_absent_keys_return_the_milestone_6_defaults(): void
    {
        $settings = $this->settings([]);

        $this->assertSame('18:00', $settings->nightFrom());
        $this->assertNull($settings->overtimeAfterWeekly());
        $this->assertTrue($settings->occurrences());
        $this->assertTrue($settings->suspensionCharge());
        $this->assertFalse($settings->premiumHours());
        $this->assertSame(MissingSide::Void, $settings->missingSide());
    }

    public function test_a_key_present_as_json_null_returns_the_default(): void
    {
        $settings = $this->settings([
            'night_from' => null,
            'overtime_after_weekly' => null,
            'occurrences' => null,
            'suspension_charge' => null,
            'premium_hours' => null,
            'missing_side' => null,
        ]);

        $this->assertSame('18:00', $settings->nightFrom());
        $this->assertNull($settings->overtimeAfterWeekly());
        $this->assertTrue($settings->occurrences());
        $this->assertTrue($settings->suspensionCharge());
        $this->assertFalse($settings->premiumHours());
        $this->assertSame(MissingSide::Void, $settings->missingSide());
    }

    public function test_stored_values_round_trip_except_weekly_hours_become_minutes(): void
    {
        $settings = $this->settings([
            'night_from' => '22:00',
            'overtime_after_weekly' => 48,
            'occurrences' => false,
            'suspension_charge' => false,
            'premium_hours' => true,
            'missing_side' => 'assume',
        ]);

        $this->assertSame('22:00', $settings->nightFrom());
        $this->assertSame(2880, $settings->overtimeAfterWeekly());
        $this->assertFalse($settings->occurrences());
        $this->assertFalse($settings->suspensionCharge());
        $this->assertTrue($settings->premiumHours());
        $this->assertSame(MissingSide::Assume, $settings->missingSide());
    }

    public function test_a_zero_weekly_ceiling_is_zero_minutes_not_the_null_default(): void
    {
        $settings = $this->settings(['overtime_after_weekly' => 0]);

        $this->assertSame(0, $settings->overtimeAfterWeekly());
    }

    /** @param  array<string, mixed>  $stored */
    private function settings(array $stored): Settings
    {
        $agency = new Agency;
        $agency->settings = $stored;

        return new Settings($agency);
    }
}
