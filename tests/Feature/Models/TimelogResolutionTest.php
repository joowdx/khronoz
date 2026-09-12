<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Terminal;
use App\Models\Timelog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimelogResolutionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function rawTimelog(Enrollment $enrollment, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $enrollment->agency_id,
            'terminal_id' => $enrollment->terminal_id,
            'sync_id' => null,
            'employee_id' => null,
            'enrollment_id' => null,
            'uid' => $enrollment->uid,
            'time' => $enrollment->starts->copy()->addDays(3)->setTime(8, 0)->toDateTimeString(),
            'state' => 0,
            'mode' => 1,
            'source' => 'manual',
            'user_id' => User::factory()->create(['agency_id' => $enrollment->agency_id])->id,
            'voided_at' => null,
            'reason' => null,
            'created_at' => now(),
            ...$overrides,
        ];
    }

    public function test_a_punch_on_an_enrolled_uid_resolves_to_that_employee(): void
    {
        $enrollment = Enrollment::factory()->create();
        $timelog = Timelog::factory()->resolving($enrollment)->create();

        $resolved = $timelog->fresh();

        $this->assertSame($enrollment->employee_id, $resolved->employee_id);
        $this->assertSame($enrollment->id, $resolved->enrollment_id);
    }

    public function test_a_punch_outside_every_enrollment_stays_unresolved_and_visible(): void
    {
        $enrollment = Enrollment::factory()->closed('+1 month')->create();

        $timelog = Timelog::factory()->resolving($enrollment)->create([
            'time' => $enrollment->ends->copy()->addDay()->setTime(8, 0)->toDateTimeString(),
        ]);

        $resolved = $timelog->fresh();

        $this->assertNull($resolved->employee_id);
        $this->assertNull($resolved->enrollment_id);
        $this->assertDatabaseHas('timelogs', ['id' => $timelog->id]);
    }

    public function test_the_database_overwrites_whatever_the_client_claims(): void
    {
        $enrollment = Enrollment::factory()->create();
        $impostor = Employee::factory()->create(['agency_id' => $enrollment->agency_id]);

        $id = (string) Str::ulid();

        DB::table('timelogs')->insert($this->rawTimelog($enrollment, [
            'id' => $id,
            'employee_id' => $impostor->id,
            'enrollment_id' => $enrollment->id,
        ]));

        $row = DB::table('timelogs')->where('id', $id)->first();

        $this->assertSame($enrollment->employee_id, $row->employee_id);
        $this->assertNotSame($impostor->id, $row->employee_id);
    }

    public function test_a_reissued_uid_resolves_each_punch_to_its_own_holder(): void
    {
        $leaver = Enrollment::factory()->closed('+1 month')->create();
        $this->withTenant(Agency::findOrFail($leaver->agency_id));

        $terminal = Terminal::findOrFail($leaver->terminal_id);
        $joiner = Employee::factory()->create(['agency_id' => $leaver->agency_id]);

        $successor = Enrollment::factory()->on($terminal)->forEmployee($joiner)->create([
            'uid' => $leaver->uid,
            'starts' => $leaver->ends->copy()->addDay(),
        ]);

        $early = Timelog::factory()->resolving($leaver)->create();
        $late = Timelog::factory()->resolving($successor)->create();

        $this->assertSame($leaver->employee_id, $early->fresh()->employee_id);
        $this->assertSame($successor->employee_id, $late->fresh()->employee_id);
        $this->assertNotSame($early->fresh()->employee_id, $late->fresh()->employee_id);
    }

    public function test_a_leading_zero_uid_is_a_different_person(): void
    {
        $enrollment = Enrollment::factory()->create(['uid' => '007']);
        $this->withTenant(Agency::findOrFail($enrollment->agency_id));

        $terminal = Terminal::findOrFail($enrollment->terminal_id);

        $punch = Timelog::factory()->on($terminal)->create([
            'uid' => '7',
            'time' => $enrollment->starts->copy()->addDays(3)->setTime(8, 0)->toDateTimeString(),
        ]);

        $this->assertNull($punch->fresh()->employee_id);
    }

    public function test_an_alphanumeric_uid_resolves_like_any_other(): void
    {
        $enrollment = Enrollment::factory()->create(['uid' => 'A17']);
        $timelog = Timelog::factory()->resolving($enrollment)->create();

        $this->assertSame($enrollment->employee_id, $timelog->fresh()->employee_id);
    }

    public function test_creating_an_enrollment_resolves_punches_already_ingested(): void
    {
        $terminal = Terminal::factory()->create();
        $this->withTenant(Agency::findOrFail($terminal->agency_id));

        $orphan = Timelog::factory()->on($terminal)->create([
            'uid' => '0042',
            'time' => '2026-03-10 08:00:00',
        ]);

        $this->assertNull($orphan->fresh()->employee_id);

        $enrollment = Enrollment::factory()->on($terminal)->create([
            'uid' => '0042',
            'starts' => '2026-03-01',
        ]);

        $this->assertSame($enrollment->employee_id, $orphan->fresh()->employee_id);
        $this->assertSame($enrollment->id, $orphan->fresh()->enrollment_id);
    }

    public function test_moving_an_enrollment_off_a_punch_unresolves_it(): void
    {
        $enrollment = Enrollment::factory()->create();
        $timelog = Timelog::factory()->resolving($enrollment)->create();

        $this->assertSame($enrollment->employee_id, $timelog->fresh()->employee_id);

        // Ends the day before the punch, so the range no longer covers it.
        $enrollment->update(['ends' => $timelog->time->copy()->subDay()->toDateString()]);

        $this->assertNull($timelog->fresh()->employee_id);
        $this->assertNull($timelog->fresh()->enrollment_id);
    }

    public function test_changing_an_enrollments_employee_reattributes_its_punches(): void
    {
        $enrollment = Enrollment::factory()->create();
        $timelog = Timelog::factory()->resolving($enrollment)->create();
        $successor = Employee::factory()->create(['agency_id' => $enrollment->agency_id]);

        $enrollment->update(['employee_id' => $successor->id]);

        $this->assertSame($successor->id, $timelog->fresh()->employee_id);
    }

    public function test_correcting_a_mistyped_uid_moves_attribution_both_ways(): void
    {
        $enrollment = Enrollment::factory()->create(['uid' => '1102', 'starts' => '2026-03-01']);
        $this->withTenant(Agency::findOrFail($enrollment->agency_id));

        $terminal = Terminal::findOrFail($enrollment->terminal_id);

        $underTypo = Timelog::factory()->on($terminal)->create(['uid' => '1102', 'time' => '2026-03-10 08:00:00']);
        $underTruth = Timelog::factory()->on($terminal)->create(['uid' => '01102', 'time' => '2026-03-11 08:00:00']);

        $this->assertSame($enrollment->employee_id, $underTypo->fresh()->employee_id);
        $this->assertNull($underTruth->fresh()->employee_id);

        $enrollment->update(['uid' => '01102']);

        $this->assertNull($underTypo->fresh()->employee_id, 'punches the device reported as 1102 must let go');
        $this->assertSame($enrollment->employee_id, $underTruth->fresh()->employee_id, 'punches reported as 01102 must attach');

        // And the device's own record of what it saw is untouched.
        $this->assertSame('1102', $underTypo->fresh()->uid);
        $this->assertSame('01102', $underTruth->fresh()->uid);
    }

    public function test_moving_an_enrollment_to_another_terminal_releases_the_old_devices_punches(): void
    {
        $enrollment = Enrollment::factory()->create(['starts' => '2026-03-01']);
        $this->withTenant(Agency::findOrFail($enrollment->agency_id));

        $lobby = Terminal::findOrFail($enrollment->terminal_id);
        $annex = Terminal::factory()->create(['agency_id' => $enrollment->agency_id]);

        $captured = Timelog::factory()->on($lobby)->create([
            'uid' => $enrollment->uid,
            'time' => '2026-03-10 08:00:00',
        ]);

        $this->assertSame($enrollment->employee_id, $captured->fresh()->employee_id);

        $enrollment->update(['terminal_id' => $annex->id]);

        $this->assertNull($captured->fresh()->employee_id);
    }

    public function test_a_temporary_table_cannot_hijack_resolution(): void
    {
        $enrollment = Enrollment::factory()->create([
            'uid' => '0042',
            'starts' => '2026-02-01',
            'ends' => '2026-02-28',
        ]);

        // A January punch: outside the enrollment, so it must stay unresolved.
        $id = (string) Str::ulid();
        DB::table('timelogs')->insert($this->rawTimelog($enrollment, [
            'id' => $id,
            'time' => '2026-01-15 08:00:00',
        ]));

        $this->assertNull($this->storedEmployee($id), 'precondition: the punch is outside the range');

        // Shadow `enrollments` with the same four key columns the paired FK
        // checks, widening only the dates — which the FK does not constrain.
        DB::statement('CREATE TEMP TABLE enrollments (id text, employee_id text, terminal_id text, uid text, starts date, ends date)');
        DB::table('enrollments')->insert([
            'id' => $enrollment->id,
            'employee_id' => $enrollment->employee_id,
            'terminal_id' => $enrollment->terminal_id,
            'uid' => '0042',
            'starts' => '2020-01-01',
            'ends' => null,
        ]);

        // The AFTER path: qualified so the UPDATE hits the real table and
        // fires the trigger, while the function's own reads are the target.
        DB::statement('UPDATE public.enrollments SET ends = ends WHERE id = ?', [$enrollment->id]);

        $this->assertNull($this->storedEmployee($id), 'enrollments_reresolve read the shadowed table');

        // And the BEFORE INSERT path, with the shadow still in place.
        $probe = (string) Str::ulid();
        DB::table('timelogs')->insert($this->rawTimelog($enrollment, [
            'id' => $probe,
            'time' => '2026-01-16 08:00:00',
        ]));

        $this->assertNull($this->storedEmployee($probe), 'timelogs_resolve read the shadowed table');
    }

    public function test_the_resolver_locks_the_enrollment_it_reads(): void
    {
        $body = DB::selectOne(
            "select pg_get_functiondef(oid) as definition from pg_proc where proname = 'timelogs_resolve'"
        )->definition;

        $this->assertStringContainsString('FOR SHARE', $body);
    }

    public function test_both_resolvers_pin_their_search_path(): void
    {
        $configured = DB::table('pg_proc')
            ->whereIn('proname', ['timelogs_resolve', 'enrollments_reresolve'])
            ->orderBy('proname')
            ->pluck('proconfig', 'proname')
            ->all();

        $this->assertCount(2, $configured);

        foreach ($configured as $name => $config) {
            $this->assertNotNull($config, "{$name} has no search_path pinned");
            $this->assertStringContainsString('search_path=', $config, "{$name} has no search_path pinned");
        }
    }

    private function storedEmployee(string $id): ?string
    {
        return DB::connection('owner')->table('timelogs')->where('id', $id)->value('employee_id');
    }

    public function test_the_upsert_returns_only_rows_it_actually_inserted(): void
    {
        $enrollment = Enrollment::factory()->create();
        $existing = Timelog::factory()->resolving($enrollment)->create();

        $rows = [
            // A duplicate of the row already present, on the natural key.
            $this->rawTimelog($enrollment, [
                'time' => $existing->time->toDateTimeString(),
                'state' => $existing->state,
                'mode' => $existing->mode,
            ]),
            // And one genuinely new punch.
            $this->rawTimelog($enrollment, [
                'time' => $existing->time->copy()->addHours(9)->toDateTimeString(),
                'state' => 1,
            ]),
        ];

        $returned = DB::table('timelogs')->insertOrIgnoreReturning(
            $rows,
            ['id', 'employee_id', 'time'],
            ['terminal_id', 'uid', 'time', 'state', 'mode'],
        );

        $this->assertCount(1, $returned);
        $this->assertSame($enrollment->employee_id, $returned->first()->employee_id);
    }
}
