<?php

namespace Tests\Feature\Models;

use App\Models\Sync;
use App\Models\Terminal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One test per constraint on `syncs` (docs/design/07-constraints.md).
 *
 * The table exists because the predecessor had nothing like it: its counts
 * rode in a fired event — one of which had no registered listener and was
 * dropped every time — and the figure it reported was the size of the input
 * rather than the number of rows inserted, because its upsert could not tell
 * the two apart. Half these tests are about making the numbers unable to lie.
 *
 * No `agency_not_platform` test and no such trigger: a sync needs a terminal,
 * and terminals refuse the platform row already.
 */
class SyncTest extends TestCase
{
    /** @return array<string, mixed> */
    private function syncRow(Sync $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'terminal_id' => $like->terminal_id,
            'trigger' => 'import',
            'status' => 'running',
            'started_at' => '2026-09-01 08:00:00',
            'finished_at' => null,
            'drift' => null,
            'received' => 0,
            'accepted' => 0,
            'duplicates' => 0,
            'rejected' => 0,
            'reference' => 'attlog.dat',
            'earliest' => null,
            'latest' => null,
            'error' => null,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_sync_needs_an_agency(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['agency_id' => null])
        ));
    }

    /** A run with no device is not a run; it is where the records came from. */
    public function test_terminal_is_required(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['terminal_id' => null])
        ));
    }

    public function test_trigger_is_required(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['trigger' => null])
        ));
    }

    public function test_status_is_required(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['status' => null])
        ));
    }

    /**
     * Without it there is no way to tell a run that is still going from one
     * that died before it began — and `syncs_times_ordered` has nothing to
     * compare against.
     */
    public function test_started_at_is_required(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['started_at' => null])
        ));
    }

    /** syncs_trigger_valid. EnumCheckContractTest holds the value list to the enum. */
    public function test_trigger_must_be_a_known_value(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['trigger' => 'cron'])
        ));
    }

    /** syncs_status_valid. */
    public function test_status_must_be_a_known_value(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['status' => 'partial'])
        ));
    }

    /** syncs_times_ordered. */
    public function test_a_run_cannot_finish_before_it_started(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['finished_at' => '2026-09-01 07:59:00'])
        ));
    }

    /**
     * The other side of that CHECK, and why it is `>=` rather than `>`: an
     * import of an empty file finishes in the same clock tick it began, and
     * refusing that would make the fastest possible run the one shape the
     * table cannot record.
     */
    public function test_a_run_may_finish_in_the_instant_it_started(): void
    {
        $sync = Sync::factory()->create();

        DB::table('syncs')->insert($this->syncRow($sync, [
            'status' => 'completed',
            'finished_at' => '2026-09-01 08:00:00',
        ]));

        $this->assertDatabaseHas('syncs', [
            'started_at' => '2026-09-01 08:00:00',
            'finished_at' => '2026-09-01 08:00:00',
        ]);
    }

    /**
     * syncs_counts_balance — the constraint this table exists for.
     *
     * The predecessor reported the size of its input as the record count,
     * which meant a re-import of an already-present file reported a
     * successful import of N records while inserting none, indistinguishable
     * from a genuine one. Here the four numbers must add up, so a writer
     * cannot report 500 received and account for 400.
     */
    public function test_the_counters_must_add_up(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, [
                'received' => 5,
                'accepted' => 1,
                'duplicates' => 1,
                'rejected' => 1,
            ])
        ));
    }

    /**
     * And the shape that makes that constraint usable rather than merely
     * strict: a freshly opened run is all zeroes, which satisfies it, so the
     * importer can open a `running` row before it has read a single line.
     *
     * This is load-bearing for the writer. Because the CHECK must hold on
     * every row at every moment, the four counters cannot be incremented as
     * the file streams — the first `received = 1` with the rest at zero fires
     * 23514. They are written once, in the UPDATE that closes the run.
     */
    public function test_a_freshly_opened_run_is_all_zeroes(): void
    {
        $sync = Sync::factory()->create();

        $this->assertSame(0, $sync->received);
        $this->assertSame(0, $sync->accepted);
        $this->assertSame(0, $sync->duplicates);
        $this->assertSame(0, $sync->rejected);

        // And the column defaults themselves, which the assertions above
        // cannot reach: .ai/rules/factories.md requires a factory to set every
        // column explicitly, so `SyncFactory` names all four and the database
        // default is never exercised by them. Mutation testing found that
        // changing `default(0)` to `default(1)` left this test green.
        //
        // The default is what lets the importer open a run by naming only its
        // identity — `syncs_counts_balance` is satisfied by 0 = 0 + 0 + 0, so
        // an INSERT that mentions no counter at all is a legal opening row.
        $opened = (string) Str::ulid();

        DB::table('syncs')->insert([
            'id' => $opened,
            'agency_id' => $sync->agency_id,
            'terminal_id' => $sync->terminal_id,
            'trigger' => 'import',
            'status' => 'running',
            'started_at' => '2026-09-01 08:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('syncs', [
            'id' => $opened,
            'received' => 0,
            'accepted' => 0,
            'duplicates' => 0,
            'rejected' => 0,
        ]);
    }

    /**
     * syncs_counts_nonnegative.
     *
     * Adding up is not enough: received = 0, accepted = -4, duplicates
     * = 4, rejected = 0 still balances and records an impossible completed
     * run. The numbers here are chosen so syncs_counts_balance passes and
     * only this CHECK can fire.
     */
    public function test_a_counter_cannot_be_negative(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, [
                'received' => 0,
                'accepted' => -4,
                'duplicates' => 4,
                'rejected' => 0,
            ])
        ));
    }

    /**
     * syncs_span_paired, from the side that reads as the honest mistake: a
     * writer that tracked the first timestamp and forgot the last.
     */
    public function test_a_span_cannot_have_only_a_lower_bound(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['earliest' => '2026-09-01 07:58:00', 'latest' => null])
        ));
    }

    /** The same CHECK from the other side. */
    public function test_a_span_cannot_have_only_an_upper_bound(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['earliest' => null, 'latest' => '2026-09-01 17:04:00'])
        ));
    }

    /**
     * syncs_span_ordered, and the reason `earliest`/`latest` are in this table
     * at all.
     *
     * The predecessor took its run's span from the **first and last rows of
     * the file** rather than the minimum and maximum timestamps. An export
     * listing 30 September before 1 September therefore produced an inverted
     * range, and the recompute that followed queried `whereBetween(later,
     * earlier)`, matched nothing, and silently skipped every row the import
     * had just inserted. Nothing failed; the work simply did not happen.
     *
     * Both bounds are set here so syncs_span_paired passes and only this CHECK
     * can fire.
     */
    public function test_a_span_cannot_end_before_it_begins(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, [
                'earliest' => '2026-09-30 17:04:00',
                'latest' => '2026-09-01 07:58:00',
            ])
        ));
    }

    /** syncs_terminal_id_agency_id_foreign, insert side. */
    public function test_terminal_must_share_the_syncs_agency(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Sync::factory()->create(['terminal_id' => $terminal->id]));
    }

    /**
     * Same FK, delete side. A run record that vanishes with its terminal is a
     * run that can be denied — which is why every FK into `terminals` is
     * RESTRICT and the predecessor's `cascadeOnDelete` is not repeated.
     */
    public function test_terminal_with_a_sync_cannot_be_deleted(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('terminals')->where('id', $sync->terminal_id)->delete());
    }

    /** Ruling P4: the primary key masks the pair, so assert the catalog. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'syncs_id_agency_id_unique'"));
    }

    /**
     * syncs_id_terminal_id_unique (Ruling P4): the target of the paired
     * (sync_id, terminal_id) FK on timelogs. The primary key masks any
     * violation of the pair, so assert the catalog — dropping it would
     * silently take the "same terminal" guarantee with it.
     */
    public function test_id_and_terminal_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'syncs_id_terminal_id_unique'"));
    }
}
