<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Scopes\NotPlatformScope;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgencyTest extends TestCase
{
    public function test_rest_day_after_is_bounded_to_the_week(): void
    {
        $agency = Agency::factory()->create();

        foreach ([0, 7, -1, 1.5, '3', true, []] as $illegal) {
            $this->assertDatabaseRefuses('23514', fn () => DB::table('agencies')
                ->where('id', $agency->id)
                ->update(['settings' => json_encode(['rest_day_after' => $illegal])]));
        }

        foreach (range(1, 6) as $legal) {
            DB::table('agencies')->where('id', $agency->id)
                ->update(['settings' => json_encode(['rest_day_after' => $legal])]);

            $this->assertSame($legal, $agency->fresh()->settings['rest_day_after']);
        }

        DB::table('agencies')->where('id', $agency->id)->update(['settings' => json_encode(['rest_day_after' => null])]);
        $this->assertNull($agency->fresh()->settings['rest_day_after']);

        DB::table('agencies')->where('id', $agency->id)->update(['settings' => '{}']);
        $this->assertSame([], $agency->fresh()->settings);
    }

    public function test_platform_row_is_seeded_once(): void
    {
        $this->assertTrue(Agency::platform()->platform);
        $this->assertSame(1, Agency::withoutGlobalScope(NotPlatformScope::class)->where('platform', true)->count());
    }

    public function test_platform_is_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        Agency::create(['platform' => true, 'code' => 'not-the-real-one', 'name' => 'x']);
    }

    public function test_second_platform_row_is_refused(): void
    {
        $this->assertDatabaseRefuses('23505', fn () => Agency::factory()->platform()->create(['code' => 'second']));
    }

    public function test_platform_row_cannot_be_deleted(): void
    {
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('agencies')->where('platform', true)->delete());
    }

    public function test_platform_flag_cannot_change(): void
    {
        $agency = Agency::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('agencies')->where('id', $agency->id)->update(['platform' => true]));
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('agencies')->where('platform', true)->update(['platform' => false]));
    }

    public function test_agency_lists_hide_the_platform_row(): void
    {
        Agency::factory()->count(2)->create();

        $this->assertSame(2, Agency::count());
        $this->assertFalse(Agency::query()->pluck('platform')->contains(true));
    }

    public function test_code_is_unique(): void
    {
        Agency::factory()->create(['code' => 'DOH']);

        $this->assertDatabaseRefuses('23505', fn () => Agency::factory()->create(['code' => 'DOH']));
    }

    public function test_settings_must_be_an_object(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => DB::table('agencies')->insert([
            'id' => '01J00000000000000000000000', 'code' => 'X', 'name' => 'X', 'settings' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_agency_with_users_cannot_be_deleted(): void
    {
        $agency = Agency::factory()->create();
        User::factory()->forAgency($agency)->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('agencies')->where('id', $agency->id)->delete());
    }

    public function test_platform_flag_cannot_be_null(): void
    {
        $this->assertDatabaseRefuses('23502', fn () => DB::table('agencies')->insert([
            'id' => '01J00000000000000000000001', 'code' => 'X', 'name' => 'X', 'platform' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }
}
