<?php

namespace Tests\Feature;

use App\Actions\ImportTimelogs;
use App\Enums\Permission;
use App\Enums\SyncStatus;
use App\Models\Agency;
use App\Models\Enrollment;
use App\Models\Sync;
use App\Models\Terminal;
use App\Models\Timelog;
use App\Support\AttlogParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The attlog import, end to end — the one ingestion path Milestone 5 ships
 * (decision 40).
 *
 * Most of these are named after a specific defect in the predecessor, which is
 * audited in `docs/reference/clockwork-audit.md`. The import is the piece of
 * this system that decides everyone's pay, and the predecessor's version of it
 * had two test files, both Laravel scaffolding examples.
 */
class ImportTimelogsTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function attlog(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'attlog');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    private function import(Terminal $terminal, string $path, string $reference = 'attlog.dat'): Sync
    {
        return app(ImportTimelogs::class)->handle($terminal, $path, $reference);
    }

    private function terminal(): Terminal
    {
        $terminal = Terminal::factory()->create();
        $this->withTenant(Agency::findOrFail($terminal->agency_id));

        return $terminal;
    }

    /** The happy path, and the arithmetic `syncs_counts_balance` enforces. */
    public function test_an_import_records_what_it_did(): void
    {
        $terminal = $this->terminal();

        $sync = $this->import($terminal, $this->attlog(
            "0001\t2026-09-01 08:01:23\t0\t1\n".
            "0001\t2026-09-01 17:04:56\t1\t1\n".
            "0002\t2026-09-01 08:15:00\t0\t1\n"
        ));

        $this->assertSame(SyncStatus::Completed, $sync->status);
        $this->assertSame(3, $sync->received);
        $this->assertSame(3, $sync->accepted);
        $this->assertSame(0, $sync->duplicates);
        $this->assertSame(0, $sync->rejected);
        $this->assertSame($sync->received, $sync->accepted + $sync->duplicates + $sync->rejected);
        $this->assertSame(3, Timelog::where('terminal_id', $terminal->id)->count());
    }

    /**
     * **The number the predecessor could not produce.** Its upsert was
     * `ON CONFLICT DO UPDATE`, which counts inserted and updated rows alike,
     * so a re-import of an already-present file reported a successful import
     * of N records while inserting none — indistinguishable from a real one.
     */
    public function test_re_importing_the_same_file_adds_nothing_and_says_so(): void
    {
        $terminal = $this->terminal();
        $path = $this->attlog(
            "0001\t2026-09-01 08:01:23\t0\t1\n".
            "0001\t2026-09-01 17:04:56\t1\t1\n"
        );

        $this->import($terminal, $path);
        $again = $this->import($terminal, $path);

        $this->assertSame(2, $again->received);
        $this->assertSame(0, $again->accepted);
        $this->assertSame(2, $again->duplicates);
        $this->assertSame(2, Timelog::where('terminal_id', $terminal->id)->count());

        // Nothing was accepted, so the run has no recompute window at all —
        // both bounds null, which syncs_span_paired requires.
        $this->assertNull($again->earliest);
        $this->assertNull($again->latest);
    }

    /**
     * One malformed line must not destroy the file. The predecessor threw from
     * inside its mapping closure, so a bad line at row 12,345 discarded 40,000
     * good punches — and left the chunks before it committed anyway.
     */
    public function test_one_malformed_line_does_not_destroy_the_import(): void
    {
        $terminal = $this->terminal();

        $sync = $this->import($terminal, $this->attlog(
            "0001\t2026-09-01 08:01:23\t0\t1\n".
            "this is not a punch\n".
            "0002\t2026-09-01 08:15:00\t0\t1\n"
        ));

        $this->assertSame(SyncStatus::Completed, $sync->status);
        $this->assertSame(3, $sync->received);
        $this->assertSame(2, $sync->accepted);
        $this->assertSame(1, $sync->rejected);
        $this->assertSame(2, Timelog::where('terminal_id', $terminal->id)->count());
    }

    /**
     * An empty uid is rejected, never stored. The predecessor never checked,
     * so a leading empty field wrote a blank uid — an unassignable punch that
     * collided with every other blank-uid punch at the same instant.
     */
    public function test_a_row_with_no_uid_is_rejected(): void
    {
        $terminal = $this->terminal();

        $sync = $this->import($terminal, $this->attlog(
            "\t2026-09-01 08:01:23\t0\t1\n".
            "0002\t2026-09-01 08:15:00\t0\t1\n"
        ));

        $this->assertSame(1, $sync->rejected);
        $this->assertSame(1, $sync->accepted);
        $this->assertSame(0, Timelog::where('uid', '')->count());
    }

    /**
     * A non-integer mode dies at the parser, not at Postgres mid-chunk. The
     * predecessor gated on `is_numeric`, which accepts `1.5` and `1e3`; the
     * row then passed PHP and was refused by the integer column after earlier
     * chunks had already committed.
     */
    public function test_a_non_integer_mode_is_rejected_by_the_parser(): void
    {
        $terminal = $this->terminal();

        $sync = $this->import($terminal, $this->attlog(
            "0001\t2026-09-01 08:01:23\t0\t1.5\n".
            "0002\t2026-09-01 08:15:00\t0\t1\n"
        ));

        $this->assertSame(SyncStatus::Completed, $sync->status);
        $this->assertSame(1, $sync->rejected);
        $this->assertSame(1, $sync->accepted);
    }

    /**
     * **The span is min and max, not first and last.** The predecessor took
     * its range from the first and last rows of the file, so an export listing
     * 30 September before 1 September produced an inverted window; the
     * recompute that followed matched nothing and silently skipped every row
     * the import had just inserted.
     */
    public function test_an_out_of_order_file_still_records_the_true_span(): void
    {
        $terminal = $this->terminal();

        $sync = $this->import($terminal, $this->attlog(
            "0001\t2026-09-30 17:04:00\t1\t1\n".
            "0001\t2026-09-15 08:00:00\t0\t1\n".
            "0001\t2026-09-01 07:58:00\t0\t1\n"
        ));

        $this->assertSame('2026-09-01 07:58:00', $sync->earliest->toDateTimeString());
        $this->assertSame('2026-09-30 17:04:00', $sync->latest->toDateTimeString());
    }

    /** Rows for enrolled uids come out resolved; unknown uids come out present but unresolved. */
    public function test_enrolled_uids_resolve_and_unknown_ones_stay_visible(): void
    {
        $terminal = $this->terminal();
        $enrollment = Enrollment::factory()->on($terminal)->create([
            'uid' => '0001',
            'starts' => '2026-01-01',
        ]);

        $sync = $this->import($terminal, $this->attlog(
            "0001\t2026-09-01 08:01:23\t0\t1\n".
            "9999\t2026-09-01 08:15:00\t0\t1\n"
        ));

        $this->assertSame(2, $sync->accepted);
        $this->assertSame($enrollment->employee_id, Timelog::where('uid', '0001')->sole()->employee_id);
        $this->assertNull(Timelog::where('uid', '9999')->sole()->employee_id);
    }

    /** Decision 42: `A17` is data, not a crash. The predecessor's `int()` raised on it. */
    public function test_an_alphanumeric_uid_imports(): void
    {
        $terminal = $this->terminal();

        $sync = $this->import($terminal, $this->attlog("A17\t2026-09-01 08:01:23\t0\t1\n"));

        $this->assertSame(1, $sync->accepted);
        $this->assertSame('A17', Timelog::sole()->uid);
    }

    /** Decision 42 again: `007` and `7` are two people, all the way through the import. */
    public function test_a_leading_zero_uid_is_not_the_same_person(): void
    {
        $terminal = $this->terminal();
        Enrollment::factory()->on($terminal)->create(['uid' => '7', 'starts' => '2026-01-01']);

        $this->import($terminal, $this->attlog("007\t2026-09-01 08:01:23\t0\t1\n"));

        $this->assertSame('007', Timelog::sole()->uid);
        $this->assertNull(Timelog::sole()->employee_id);
    }

    /**
     * One statement carrying the same natural key twice inserts one row and
     * does not error. The predecessor's `DO UPDATE` raised 21000 here, and its
     * workaround buffered the entire file in memory to avoid it.
     */
    public function test_a_file_repeating_one_punch_inserts_it_once(): void
    {
        $terminal = $this->terminal();

        $sync = $this->import($terminal, $this->attlog(
            "0001\t2026-09-01 08:01:23\t0\t1\n".
            "0001\t2026-09-01 08:01:23\t0\t1\n"
        ));

        $this->assertSame(2, $sync->received);
        $this->assertSame(1, $sync->accepted);
        $this->assertSame(1, $sync->duplicates);
        $this->assertSame(1, Timelog::count());
    }

    /**
     * **An import is not a device read** (decision 40). The predecessor
     * advanced `synced_at` on import, so bringing in an old month's export
     * marked the device freshly synced; advancing `stamp` would make the first
     * real pull skip everything after it.
     */
    public function test_an_import_does_not_move_the_devices_read_position(): void
    {
        $terminal = $this->terminal();

        $this->import($terminal, $this->attlog("0001\t2026-09-01 08:01:23\t0\t1\n"));

        $terminal->refresh();

        $this->assertNull($terminal->stamp);
        $this->assertNull($terminal->synced_at);
        $this->assertNull($terminal->seen_at);
    }

    /** The device-layout dialect, where field 2 is the device number. */
    public function test_the_device_layout_reads_state_and_mode_past_the_device_column(): void
    {
        $terminal = $this->terminal();

        $sync = app(ImportTimelogs::class)->handle(
            $terminal,
            $this->attlog("0001\t2026-09-01 08:01:23\t{$terminal->code}\t1\t4\t0\n"),
            'attlog.dat',
            AttlogParser::LAYOUT_DEVICE,
        );

        $this->assertSame(1, $sync->accepted);
        $this->assertSame(1, Timelog::sole()->state);
        $this->assertSame(4, Timelog::sole()->mode);
    }

    /**
     * **A file imported into the wrong terminal must not silently succeed.**
     *
     * An attlog carries no ULID. Its `device` column is the only statement it
     * makes about which scanner produced these punches, so the operator's
     * choice of terminal is an unverifiable claim unless that column is
     * checked against it. Before the check, a file saying `device 7` imported
     * cleanly into a terminal coded `3`: two punches accepted, none rejected,
     * every one attributed to a device that never recorded them.
     *
     * The whole run is refused, and refused **before anything is written**.
     */
    public function test_a_file_recorded_by_another_device_is_refused_whole(): void
    {
        $terminal = Terminal::factory()->create(['code' => '3']);
        $this->withTenant(Agency::findOrFail($terminal->agency_id));

        $sync = app(ImportTimelogs::class)->handle(
            $terminal,
            $this->attlog("0001\t2026-09-01 08:01:23\t7\t0\t1\t0\n"),
            'attlog.dat',
            AttlogParser::LAYOUT_DEVICE,
        );

        $this->assertSame(SyncStatus::Failed, $sync->status);
        $this->assertSame(0, $sync->accepted);
        $this->assertStringContainsString('recorded by device 7', $sync->error);
        $this->assertSame(0, Timelog::count());
    }

    /**
     * **A file naming more than one device is refused outright, and rejecting
     * it row by row would be strictly worse than doing nothing.**
     *
     * A scanner exports its own log, so several device numbers in one file
     * means it was merged or altered. The predecessor refused such files with
     * an error that said "likelihood of being tampered with", and that was
     * right.
     *
     * The first implementation here rejected the offending *rows* instead, and
     * this test is the measurement that killed it: a genuine device-7 export
     * with two forged device-3 rows appended, run once against each terminal,
     * stored every row including both forgeries — each run reporting an
     * unremarkable two accepted, two rejected. Per-row rejection does not
     * refuse a mixed file, it splits it, and two runs reassemble it.
     */
    public function test_a_file_naming_two_devices_is_refused_under_every_terminal(): void
    {
        $lobby = Terminal::factory()->create(['code' => '7']);
        $this->withTenant(Agency::findOrFail($lobby->agency_id));
        $annex = Terminal::factory()->create(['agency_id' => $lobby->agency_id, 'code' => '3']);

        $tampered = $this->attlog(
            "0001\t2026-09-01 08:00:00\t7\t0\t1\t0\n".
            "0001\t2026-09-01 17:00:00\t7\t1\t1\t0\n".
            "0009\t2026-09-01 07:55:00\t3\t0\t1\t0\n".
            "0009\t2026-09-01 18:30:00\t3\t1\t1\t0\n"
        );

        foreach ([$lobby, $annex] as $terminal) {
            $sync = app(ImportTimelogs::class)->handle(
                $terminal,
                $tampered,
                'attlog.dat',
                AttlogParser::LAYOUT_DEVICE,
            );

            $this->assertSame(SyncStatus::Failed, $sync->status);
            $this->assertStringContainsString('more than one device', $sync->error);
        }

        $this->assertSame(0, Timelog::count(), 'a tampered file must not import under any terminal');
    }

    /**
     * The refusal is **recorded**, not merely returned. A tampering attempt is
     * evidence: without a row, the second attempt looks exactly like the first
     * and nothing accumulates for anyone to notice.
     */
    public function test_a_refused_file_still_leaves_a_failed_run_on_the_record(): void
    {
        $terminal = Terminal::factory()->create(['code' => '3']);
        $this->withTenant(Agency::findOrFail($terminal->agency_id));

        app(ImportTimelogs::class)->handle(
            $terminal,
            $this->attlog("0001\t2026-09-01 08:01:23\t7\t0\t1\t0\n"),
            'evidence.dat',
            AttlogParser::LAYOUT_DEVICE,
        );

        $sync = Sync::sole();

        $this->assertSame(SyncStatus::Failed, $sync->status);
        $this->assertSame('evidence.dat', $sync->reference);
        $this->assertNotNull($sync->error);
        $this->assertNotNull($sync->finished_at);
        $this->assertSame(0, $sync->received);
    }

    /**
     * A numeric device code must compare as a **string**, per decision 42.
     *
     * This is a regression test for a bug written into the tamper check
     * itself: collecting the distinct codes into an array *keyed* by the
     * device number let PHP coerce the numeric-string key to an integer, so
     * the code came back as `int 7` and `7 !== '7'` refused every correctly
     * matched file. The check meant to enforce decision 42 broke it.
     */
    public function test_a_numeric_device_code_matches_as_a_string(): void
    {
        $terminal = Terminal::factory()->create(['code' => '7']);
        $this->withTenant(Agency::findOrFail($terminal->agency_id));

        $sync = app(ImportTimelogs::class)->handle(
            $terminal,
            $this->attlog("0001\t2026-09-01 08:01:23\t7\t0\t1\t0\n"),
            'attlog.dat',
            AttlogParser::LAYOUT_DEVICE,
        );

        $this->assertSame(SyncStatus::Completed, $sync->status);
        $this->assertSame(1, $sync->accepted);
    }

    /**
     * The standard layout has no device column, so there is nothing to check
     * and the operator's choice stands. Stated as a test because it is the
     * limit of the guarantee above, not an oversight in it: the file genuinely
     * does not say which scanner it came from.
     */
    public function test_a_standard_layout_file_cannot_be_checked_against_the_terminal(): void
    {
        $terminal = Terminal::factory()->create(['code' => '3']);
        $this->withTenant(Agency::findOrFail($terminal->agency_id));

        $sync = $this->import($terminal, $this->attlog("0001\t2026-09-01 08:01:23\t0\t1\n"));

        $this->assertSame(1, $sync->accepted);
        $this->assertSame(0, $sync->rejected);
    }

    /** Chunking is an implementation detail; the counts must not depend on it. */
    public function test_the_counts_are_the_same_whatever_the_chunk_size(): void
    {
        $terminal = $this->terminal();

        $lines = '';

        for ($minute = 0; $minute < 25; $minute++) {
            $lines .= sprintf("0001\t2026-09-01 08:%02d:00\t0\t1\n", $minute);
        }

        $sync = app(ImportTimelogs::class)->handle(
            $terminal,
            $this->attlog($lines),
            'attlog.dat',
            AttlogParser::LAYOUT_STANDARD,
            4,
        );

        $this->assertSame(25, $sync->received);
        $this->assertSame(25, $sync->accepted);
        $this->assertSame('2026-09-01 08:00:00', $sync->earliest->toDateTimeString());
        $this->assertSame('2026-09-01 08:24:00', $sync->latest->toDateTimeString());
    }

    /** The command is the entry point that needs no browser. */
    public function test_the_artisan_command_imports(): void
    {
        $terminal = $this->terminal();
        $path = $this->attlog("0001\t2026-09-01 08:01:23\t0\t1\n");

        $this->artisan("timelogs:import {$terminal->code} {$path}")->assertSuccessful();

        $this->assertSame(1, Timelog::where('terminal_id', $terminal->id)->count());
    }

    public function test_the_command_refuses_an_unreadable_path(): void
    {
        $terminal = $this->terminal();

        $this->artisan("timelogs:import {$terminal->code} /nope/missing.dat")->assertFailed();
    }

    /**
     * The endpoint, and the thing it must not leave behind: the upload holds
     * device ids and punch times for the whole agency, and the predecessor
     * never deleted a single one of them.
     */
    public function test_the_endpoint_imports_and_deletes_the_upload(): void
    {
        $terminal = $this->terminal();
        $this->actingAsAgency(Agency::findOrFail($terminal->agency_id), Permission::ManageTerminals);

        $upload = UploadedFile::fake()->createWithContent('attlog.dat', "0001\t2026-09-01 08:01:23\t0\t1\n");
        $path = $upload->getRealPath();

        $this->post(route('terminals.syncs.store', $terminal), ['file' => $upload])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, Timelog::where('terminal_id', $terminal->id)->count());
        $this->assertFileDoesNotExist($path);
    }

    /** terminals.manage, not terminals.view: adding punches is not reading them. */
    public function test_the_endpoint_refuses_a_user_who_may_only_view_terminals(): void
    {
        $terminal = $this->terminal();
        $this->actingAsAgency(Agency::findOrFail($terminal->agency_id), Permission::ViewTerminals);

        $this->post(route('terminals.syncs.store', $terminal), [
            'file' => UploadedFile::fake()->createWithContent('attlog.dat', "0001\t2026-09-01 08:01:23\t0\t1\n"),
        ])->assertForbidden();

        $this->assertSame(0, Timelog::count());
    }

    /** Another agency's terminal is a 404, not something this endpoint can write to. */
    public function test_the_endpoint_cannot_reach_another_agencys_terminal(): void
    {
        $theirs = Terminal::factory()->create();
        $mine = Terminal::factory()->create();

        $this->actingAsAgency(Agency::findOrFail($mine->agency_id), Permission::ManageTerminals);

        $this->post(route('terminals.syncs.store', $theirs), [
            'file' => UploadedFile::fake()->createWithContent('attlog.dat', "0001\t2026-09-01 08:01:23\t0\t1\n"),
        ])->assertNotFound();

        $this->assertSame(0, DB::table('timelogs')->count());
    }
}
