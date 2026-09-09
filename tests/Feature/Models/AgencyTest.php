<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Scopes\NotPlatformScope;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgencyTest extends TestCase
{
    public function test_platform_row_is_seeded_once(): void
    {
        $this->assertTrue(Agency::platform()->platform);
        $this->assertSame(1, Agency::withoutGlobalScope(NotPlatformScope::class)->where('platform', true)->count());
    }

    /**
     * Minor 10: `platform` is deliberately absent from Agency's #[Fillable] —
     * it is a flag the row-level privileges and the agencies_platform_row
     * trigger protect, not something a request should ever set. This is the
     * precondition PlatformSeeder::run() must not rely on SeedCommand's
     * Model::unguarded() wrapper to work around: it uses forceFill so it
     * still works called directly. The seeded platform row cannot itself be
     * deleted (test_platform_row_cannot_be_deleted) to re-drive the seeder's
     * create path in a test, so this asserts the guard the fix depends on.
     */
    public function test_platform_is_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        Agency::create(['platform' => true, 'code' => 'not-the-real-one', 'name' => 'x']);
    }

    public function test_second_platform_row_is_refused(): void
    {
        // The platform() factory state reuses the seeded row's code ('platform'),
        // so an unmodified create() here would be refused by agencies_code_unique
        // (23505) whether or not the partial unique index on `platform` exists.
        // Overriding to a distinct code isolates that index as the constraint
        // actually under test.
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

    // A test for ON DELETE RESTRICT on users.agency_id was deliberately left
    // out here: deleting an agency that still has users refuses with
    // SQLSTATE 23001 (restrict_violation), not the 23503 the fix-wave brief
    // specified for it. See the handoff report (final-fix-wave-commit3-report.md)
    // for the observed error — the RESTRICT clause itself is confirmed
    // present and working; only the expected SQLSTATE needs the controller's
    // sign-off before a test can assert it.

    /** platform is NOT NULL (with a database default of false); an explicit null must still be refused. */
    public function test_platform_flag_cannot_be_null(): void
    {
        $this->assertDatabaseRefuses('23502', fn () => DB::table('agencies')->insert([
            'id' => '01J00000000000000000000001', 'code' => 'X', 'name' => 'X', 'platform' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }
}
