<?php

namespace Tests\Feature\Models;

use App\Models\Enrollment;
use App\Models\Terminal;
use App\Models\Timelog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One test per constraint on `timelogs` (docs/design/07-constraints.md).
 * Resolution behaviour — the two triggers — is TimelogResolutionTest.
 *
 * No `agency_not_platform` test and no such trigger: a timelog needs a
 * terminal, and terminals refuse the platform row already.
 */
class TimelogTest extends TestCase
{
    /** @return array<string, mixed> */
    private function timelogRow(Timelog $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'terminal_id' => $like->terminal_id,
            'sync_id' => $like->sync_id,
            'employee_id' => null,
            'enrollment_id' => null,
            'uid' => (string) fake()->unique()->numberBetween(10000, 99999),
            'time' => '2026-09-02 08:01:23',
            'state' => 0,
            'mode' => 1,
            'source' => 'device',
            'user_id' => null,
            'voided_at' => null,
            'reason' => null,
            'created_at' => now(),
            ...$overrides,
        ];
    }

    public function test_timelog_needs_an_agency(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['agency_id' => null])
        ));
    }

    /** Without it nothing says which device recorded this, and the natural key loses a column. */
    public function test_terminal_is_required(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['terminal_id' => null])
        ));
    }

    /** The device user id is how the row is attributed to a person at all. */
    public function test_uid_is_required(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['uid' => null])
        ));
    }

    public function test_time_is_required(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['time' => null])
        ));
    }

    public function test_state_is_required(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['state' => null])
        ));
    }

    public function test_mode_is_required(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['mode' => null])
        ));
    }

    public function test_source_is_required(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['source' => null])
        ));
    }

    /** timelogs_source_valid. EnumCheckContractTest holds the list to the enum. */
    public function test_source_must_be_a_known_value(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['source' => 'imported'])
        ));
    }

    /**
     * timelogs_state_valid. Postgres has no tinyint — Laravel's
     * unsignedTinyInteger is a smallint — so this CHECK is the only thing
     * keeping the value inside the byte the device actually sends.
     */
    public function test_state_must_fit_in_a_byte(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['state' => 256])
        ));
    }

    /** timelogs_mode_valid, same reasoning. */
    public function test_mode_must_fit_in_a_byte(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['mode' => 256])
        ));
    }

    /**
     * An **unrecognised but in-range** state is stored, not refused
     * (03-terminals.md rule 6). The attlog table documents 0–5; firmware in
     * the field emits others, and a row rejected for being unfamiliar is a
     * punch silently lost.
     */
    public function test_an_unknown_but_in_range_state_is_kept(): void
    {
        $timelog = Timelog::factory()->create(['state' => 9]);

        $this->assertSame(9, $timelog->fresh()->state);
    }

    /** timelogs_source_pairs_sync: a device row must name the run that brought it in. */
    public function test_a_device_timelog_must_name_its_sync(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['source' => 'device', 'sync_id' => null])
        ));
    }

    /**
     * The same CHECK from the other side, which is the half a one-directional
     * rule would miss: a manual entry must not borrow a run's provenance.
     * `user_id` is set so timelogs_manual_needs_user passes and only this
     * CHECK can fire.
     */
    public function test_a_manual_timelog_cannot_name_a_sync(): void
    {
        $timelog = Timelog::factory()->create();
        $user = User::factory()->create(['agency_id' => $timelog->agency_id]);

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['source' => 'manual', 'user_id' => $user->id])
        ));
    }

    /** timelogs_manual_needs_user — MC 21 s. 1991: who recorded it. */
    public function test_a_manual_timelog_must_name_the_user_who_entered_it(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['source' => 'manual', 'sync_id' => null, 'user_id' => null])
        ));
    }

    /**
     * timelogs_void_needs_reason. Voiding is the only correction this table
     * allows, and an unexplained void removes a punch from the record with
     * nothing to audit — strictly worse than leaving it standing.
     */
    public function test_a_void_must_carry_a_reason(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['voided_at' => now(), 'reason' => null])
        ));
    }

    /**
     * timelogs_attlog_key — the upsert target (rule 2). Re-importing a file is
     * harmless precisely because this refuses the second copy.
     */
    public function test_the_same_punch_cannot_be_recorded_twice(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, [
                'uid' => $timelog->uid,
                'time' => $timelog->time->toDateTimeString(),
                'state' => $timelog->state,
                'mode' => $timelog->mode,
            ])
        ));
    }

    /**
     * And what keeps that key honest: `state` and `mode` are **in** it, so one
     * person checking out at the same second they checked in — a double tap on
     * a device that reports both — is two rows, not one.
     *
     * Asserts the fetched rows rather than a count, for the reason
     * SuspensionTest records: a `count()` cannot detect a reader capped at
     * LIMIT 1.
     */
    public function test_two_punches_differing_only_by_state_are_both_kept(): void
    {
        $timelog = Timelog::factory()->create(['state' => 0]);

        DB::table('timelogs')->insert($this->timelogRow($timelog, [
            'uid' => $timelog->uid,
            'time' => $timelog->time->toDateTimeString(),
            'state' => 1,
            'mode' => $timelog->mode,
        ]));

        $this->assertSame(
            [0, 1],
            DB::table('timelogs')->where('uid', $timelog->uid)->orderBy('state')->pluck('state')->all(),
        );
    }

    /** timelogs_terminal_id_agency_id_foreign, insert side. */
    public function test_terminal_must_share_the_timelogs_agency(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Timelog::factory()->create(['terminal_id' => $terminal->id]));
    }

    /**
     * Same FK, delete side — the predecessor's `cascadeOnDelete` closed.
     * There, deleting a scanner deleted every punch it had ever captured.
     */
    public function test_terminal_with_a_timelog_cannot_be_deleted(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('terminals')->where('id', $timelog->terminal_id)->delete());
    }

    /**
     * timelogs_sync_id_foreign gets a catalog assertion rather than a refusal
     * test, and the reason is a consequence of the privileges rather than a
     * gap in coverage.
     *
     * The app role has **no DELETE on `syncs` at all**, so through the
     * application connection this answers 42501 — the privilege fires before
     * the foreign key is ever consulted, and a test written against it would
     * re-prove what TimelogImmutabilityTest already covers while leaving the
     * FK itself uncovered. Nor can the owner connection stand in: it is a
     * separate session and cannot see rows this test has not committed.
     *
     * So the two layers are asserted where each is reachable. The privilege
     * stops the application and is tested there; the foreign key stops
     * everybody else — a migration, a console command run as the owner, a DBA
     * at a psql prompt — and is asserted here, on the catalog, the same move
     * Ruling P4 makes for a constraint the primary key masks.
     */
    public function test_the_sync_foreign_key_restricts_deletion(): void
    {
        $this->assertSame(
            'FOREIGN KEY (sync_id) REFERENCES syncs(id) ON UPDATE RESTRICT ON DELETE RESTRICT',
            DB::selectOne("select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'timelogs_sync_id_foreign'")->def,
        );
    }

    /** timelogs_user_id_foreign, delete side: the person who entered it stays nameable. */
    public function test_user_who_entered_a_timelog_cannot_be_deleted(): void
    {
        $timelog = Timelog::factory()->manual()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('users')->where('id', $timelog->user_id)->delete());
    }

    /**
     * timelogs_enrollment_foreign, delete side. **End an enrollment with
     * `ends`; never delete one** — deleting it would orphan every punch that
     * resolved through it, which is exactly how the predecessor made an
     * employee's history anonymous.
     */
    public function test_enrollment_with_a_resolved_timelog_cannot_be_deleted(): void
    {
        $enrollment = Enrollment::factory()->create();
        $timelog = Timelog::factory()->resolving($enrollment)->create();

        $this->assertNotNull($timelog->fresh()->enrollment_id);
        $this->assertDatabaseRefuses('23001', fn () => DB::table('enrollments')->where('id', $enrollment->id)->delete());
    }

    /** Ruling P4: the primary key masks the pair. It is M6's punch FK target. */
    public function test_id_and_employee_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'timelogs_id_employee_id_unique'"));
    }

    /** Ruling P4 again, for the tenancy pair. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'timelogs_id_agency_id_unique'"));
    }

    /**
     * `timelogs_resolved_pair` gets a catalog assertion rather than a refusal
     * test, and the reason is worth stating because it looks like a gap.
     *
     * It has **no reachable violation**. `timelogs_resolve` runs BEFORE every
     * INSERT and writes both columns from one `SELECT ... INTO`, which either
     * finds a row and sets both or finds none and leaves both null — never one
     * of each. And after the next commit the app role cannot UPDATE those
     * columns at all. So the CHECK is defence in depth against a future bug in
     * the function, exactly as 07-constraints.md describes the paired FK, and
     * a test that tried to provoke it would be asserting the trigger rather
     * than the constraint.
     */
    public function test_the_resolved_pair_check_is_declared(): void
    {
        $this->assertSame(
            'CHECK (((enrollment_id IS NULL) = (employee_id IS NULL)))',
            DB::selectOne("select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'timelogs_resolved_pair'")->def,
        );
    }

    /**
     * No `updated_at`, and this is a test rather than a comment because the
     * next commit depends on it: once UPDATE is revoked down to
     * `(voided_at, reason)`, an Eloquent write that also touched `updated_at`
     * would fail 42501, and `Timelog::void()` would stop working.
     */
    public function test_the_table_has_no_updated_at_column(): void
    {
        $this->assertNull(DB::selectOne(
            "select 1 as present from information_schema.columns where table_name = 'timelogs' and column_name = 'updated_at'"
        ));

        $timelog = Timelog::factory()->create();

        $this->assertTrue($timelog->void('Duplicate scan'));
        $this->assertNotNull($timelog->fresh()->voided_at);
        $this->assertSame('Duplicate scan', $timelog->fresh()->reason);
    }
}
