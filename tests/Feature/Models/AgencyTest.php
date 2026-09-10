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
    /**
     * agencies_rest_day_after_bounded. Art. 91 guarantees 24 consecutive hours
     * of rest after every N consecutive normal work days, and no reading of it
     * puts N outside 1 to 6 — stricter is lawful, looser is not (decision 32).
     *
     * **Null must stay legal**, as an absent key and as an explicit JSON null,
     * because a civil-service agency is genuinely not under Art. 91: it works
     * 40 hours over 5 days by rule, so the weekly rest day never binds. Since
     * decision 32 deliberately does not store which regime an agency is under,
     * no constraint can tell a lawful null from an evasive one.
     *
     * Every refusal is 23514 and never 22P02: the CHECK compares jsonb values
     * rather than casting to int, so a string or a fraction is refused by the
     * constraint rather than blowing up in a cast whose SQLSTATE would be a
     * different error entirely.
     */
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

        // An explicit null, and an absent key: both legal, and both mean the
        // rule does not bind.
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

    /**
     * users.agency_id is ON DELETE RESTRICT (docs/design/07-constraints.md's
     * global default for every paired FK, applying to ~25 tables across the
     * full plan): deleting an agency that still has users must be refused,
     * not silently cascaded or nulled.
     *
     * Refuses with SQLSTATE 23001 (restrict_violation), signed off by the
     * controller — confirmed against the live cluster: pg_constraint reports
     * users_agency_id_foreign's confdeltype as 'r' (RESTRICT), not 'a' (NO
     * ACTION). See the SQLSTATE note in .ai/rules/tests.md for how this
     * differs from insert-side 23503.
     *
     * Must target a non-platform agency: deleting the platform row itself
     * trips the agencies_platform_row trigger first (P0001, see
     * test_platform_row_cannot_be_deleted), which would prove nothing about
     * this FK.
     */
    public function test_agency_with_users_cannot_be_deleted(): void
    {
        $agency = Agency::factory()->create();
        User::factory()->forAgency($agency)->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('agencies')->where('id', $agency->id)->delete());
    }

    /** platform is NOT NULL (with a database default of false); an explicit null must still be refused. */
    public function test_platform_flag_cannot_be_null(): void
    {
        $this->assertDatabaseRefuses('23502', fn () => DB::table('agencies')->insert([
            'id' => '01J00000000000000000000001', 'code' => 'X', 'name' => 'X', 'platform' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }
}
