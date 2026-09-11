<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Enrollment;
use App\Models\Terminal;
use App\Models\Timelog;
use App\Models\Workgroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One test per constraint and trigger on `terminals`
 * (docs/design/07-constraints.md), in the shape SuspensionTest established: a
 * private row-builder, raw DB::table()->insert() so Eloquent's own casts and
 * mutators cannot stand in for the database's refusal, and
 * assertDatabaseRefuses() reading the SQLSTATE off the actual error.
 *
 * `agency_not_platform` **is** here, unlike on `holidays`, `shifts` and
 * `schedules`: a terminal is operational — it belongs to one agency's office
 * and captures one agency's punches — and nothing operational hangs under the
 * platform row (decision 26).
 */
class TerminalTest extends TestCase
{
    /** @return array<string, mixed> */
    private function terminalRow(Terminal $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'workgroup_id' => $like->workgroup_id,
            'code' => (string) fake()->unique()->numberBetween(10000, 99999),
            'name' => 'Lobby '.fake()->unique()->lexify('????'),
            'serial' => strtoupper(fake()->unique()->bothify('??####????')),
            'kind' => 'terminal',
            'protocol' => 'file',
            'host' => null,
            'port' => null,
            'secret' => null,
            'drift' => null,
            'meta' => null,
            'seen_at' => null,
            'synced_at' => null,
            'stamp' => null,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_terminal_needs_an_agency(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['agency_id' => null])
        ));
    }

    /** The device number the attlog carries; without it no punch can name its device. */
    public function test_code_is_required(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['code' => null])
        ));
    }

    public function test_name_is_required(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['name' => null])
        ));
    }

    public function test_kind_is_required(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['kind' => null])
        ));
    }

    public function test_protocol_is_required(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['protocol' => null])
        ));
    }

    /** terminals_kind_valid. EnumCheckContractTest holds the value list itself to the enum. */
    public function test_kind_must_be_a_known_value(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['kind' => 'tablet'])
        ));
    }

    /** terminals_protocol_valid. */
    public function test_protocol_must_be_a_known_value(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['protocol' => 'ftp'])
        ));
    }

    /**
     * terminals_agency_id_code_unique. The attlog identifies its device by
     * `code` alone, so two terminals of one agency sharing one would make
     * every punch ambiguous about which device captured it.
     */
    public function test_two_terminals_of_one_agency_cannot_share_a_code(): void
    {
        $terminal = Terminal::factory()->create();

        // The positive half first, and it is not decoration: mutation testing
        // found that narrowing the key to `UNIQUE (agency_id)` alone — one
        // terminal per agency, ever — still answers 23505 to the duplicate
        // below, so the refusal on its own proves nothing about which columns
        // are in the key. An agency with two terminals is the ordinary case,
        // and asserting it is what makes the refusal mean what it says.
        DB::table('terminals')->insert($this->terminalRow($terminal, ['code' => $terminal->code.'9']));

        $this->assertDatabaseRefuses('23505', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['code' => $terminal->code])
        ));
    }

    /**
     * The other half of that key, and the reason it is not a bare
     * `UNIQUE (code)`: device numbers are small integers set on the hardware,
     * so two agencies both owning a "device 1" is the normal case, not a
     * collision.
     */
    public function test_two_agencies_may_use_the_same_code(): void
    {
        $mine = Terminal::factory()->create(['code' => '1']);
        $theirs = Terminal::factory()->create(['code' => '1']);

        $this->assertNotSame($mine->agency_id, $theirs->agency_id);
        $this->assertSame('1', $mine->code);
        $this->assertSame('1', $theirs->code);
    }

    /**
     * terminals_serial, and **global** rather than per-agency: a
     * manufacturer's serial does not repeat across tenants, so two agencies
     * claiming one is a data-entry error worth refusing.
     */
    public function test_two_terminals_cannot_share_a_serial(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['serial' => $terminal->serial])
        ));
    }

    /**
     * The reason that index is **partial**, and the mutation a naive test
     * misses entirely.
     *
     * A serial is often not to hand when a device is registered, so an
     * unlimited number of terminals may have none. A plain
     * `UNIQUE (serial)` happens to permit that too — NULLS DISTINCT — so
     * removing the `WHERE serial IS NOT NULL` would leave this test passing
     * while changing what the schema says. It therefore asserts the **fetched
     * rows**, not a refusal: what is being pinned is that both rows exist and
     * both are unnamed, which is the fact the partial predicate states out
     * loud rather than leaving to a default.
     */
    public function test_serial_may_be_unknown_on_more_than_one_terminal(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);

        Terminal::factory()->create(['agency_id' => $agency->id, 'serial' => null, 'name' => 'Lobby']);
        Terminal::factory()->create(['agency_id' => $agency->id, 'serial' => null, 'name' => 'Annex']);

        $this->assertSame(
            ['Annex', 'Lobby'],
            Terminal::whereNull('serial')->orderBy('name')->pluck('name')->all(),
        );

        // And the catalog, for the same reason Ruling P4 asserts the masked
        // `(id, agency_id)` pair below: NULLS DISTINCT *masks* this predicate.
        // Dropping `WHERE serial IS NOT NULL` changes no behaviour any insert
        // can observe — mutation testing confirmed the rows-based assertion
        // above survives it — so the predicate has to be read off the index
        // definition or it is not covered at all. It is not cosmetic: it keeps
        // the index off every serial-less row, and it says out loud that an
        // unknown serial is expected rather than tolerated by accident.
        $this->assertStringContainsString(
            'WHERE (serial IS NOT NULL)',
            DB::selectOne("select indexdef from pg_indexes where indexname = 'terminals_serial'")->indexdef,
        );
    }

    /** terminals_workgroup_id_agency_id_foreign, insert side. */
    public function test_workgroup_must_share_the_terminals_agency(): void
    {
        $workgroup = Workgroup::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Terminal::factory()->create(['workgroup_id' => $workgroup->id]));
    }

    /**
     * Same FK, delete side. A raw DELETE, not $workgroup->delete(): workgroups
     * are soft deleted, so the Eloquent call is an UPDATE the FK never sees
     * and the test would assert nothing while passing.
     */
    public function test_workgroup_with_a_terminal_cannot_be_hard_deleted(): void
    {
        $workgroup = Workgroup::factory()->create();
        $terminal = Terminal::factory()->forWorkgroup($workgroup)->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('workgroups')->where('id', $terminal->workgroup_id)->delete());
    }

    /**
     * `workgroup_id` null is agency-wide — a terminal in the lobby serving
     * everyone — and needs no sentinel row: MATCH SIMPLE skips the paired FK
     * entirely once a referencing column is null. `agency_id` still carries
     * its own FK, so the row is still proven to belong to a real agency.
     */
    public function test_an_agency_wide_terminal_names_no_workgroup(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertNull($terminal->workgroup_id);
        $this->assertDatabaseHas('terminals', ['id' => $terminal->id, 'workgroup_id' => null]);
    }

    /** terminals_agency_id_foreign, delete side. */
    public function test_agency_with_a_terminal_cannot_be_deleted(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('agencies')->where('id', $terminal->agency_id)->delete());
    }

    /** agency_not_platform on terminals: neither an INSERT under the platform agency nor an UPDATE into it. */
    public function test_a_terminal_cannot_belong_to_the_platform_agency(): void
    {
        $platform = $this->platform();

        $this->assertDatabaseRefuses('P0001', fn () => Terminal::factory()->create(['agency_id' => $platform->id]));

        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('terminals')->where('id', $terminal->id)->update(['agency_id' => $platform->id]));
    }

    /**
     * **Renumbering a device rewrites nothing.**
     *
     * `terminals.code` is the device number as it appears in the attlog, and
     * it changes: devices get reset, swapped between offices, or renumbered
     * when a second one arrives. Every timelog references `terminals.id`, an
     * immutable ULID, so a renumber touches exactly one column on one row.
     *
     * The predecessor pointed `timelogs.device` at `scanners.uid` — the
     * number itself — under `ON UPDATE CASCADE`, so renumbering a device
     * silently rewrote the attlog natural key of every punch it had ever
     * captured, in two tables at once, while a `saved()` hook pushed the same
     * change into the enrollment rows. This asserts the history stays put and
     * stays attributed.
     *
     * Note also what is *not* being renamed here: `timelogs.uid` is the device
     * **user** id — which person — and is a different column entirely. The
     * audit records that the predecessor called three unrelated things `uid`.
     */
    public function test_renumbering_a_terminal_does_not_rewrite_its_history(): void
    {
        $enrollment = Enrollment::factory()->create(['uid' => '0042']);
        $this->withTenant(Agency::findOrFail($enrollment->agency_id));

        $terminal = Terminal::findOrFail($enrollment->terminal_id);
        $punch = Timelog::factory()->resolving($enrollment)->create();

        $terminal->update(['code' => '999']);

        $after = $punch->fresh();

        $this->assertSame('999', $terminal->fresh()->code);
        $this->assertSame('0042', $after->uid, 'the device user id is not the device number');
        $this->assertSame($terminal->id, $after->terminal_id);
        $this->assertSame($enrollment->employee_id, $after->employee_id, 'the punch stays attributed');
        $this->assertSame($punch->time->toDateTimeString(), $after->time->toDateTimeString());
    }

    /** Ruling P4: the primary key masks the pair, so assert the catalog. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'terminals_id_agency_id_unique'"));
    }

    /**
     * The `encrypted` cast, asserted **against the raw column** and not
     * through the model — the model would decrypt it and prove nothing.
     *
     * This exists because the predecessor kept the identical value in a plain
     * varchar, where any database export or read-only account recovered every
     * device password directly. The cast is on the model from day one, before
     * any writer exists, because adding it after the first row is written is a
     * data migration rather than a one-line change.
     */
    public function test_the_comm_key_is_not_readable_as_plaintext(): void
    {
        $terminal = Terminal::factory()->networked()->create(['secret' => 'comm-key-424242']);

        $stored = DB::table('terminals')->where('id', $terminal->id)->value('secret');

        $this->assertNotSame('comm-key-424242', $stored);
        $this->assertStringNotContainsString('424242', (string) $stored);
        $this->assertSame('comm-key-424242', $terminal->fresh()->secret);
    }
}
