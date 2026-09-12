<?php

namespace Tests\Feature;

use App\Actions\ImportTimelogs;
use App\Enums\Permission;
use App\Enums\SyncStatus;
use App\Jobs\RecomputeWorkdays;
use App\Models\Agency;
use App\Models\Enrollment;
use App\Models\Sync;
use App\Models\Terminal;
use App\Models\Timelog;
use App\Support\AttlogParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The attlog import, end to end — the one ingestion path that ships (decision 40). Most of these
 * tests are named after a specific failure mode rather than a method, because the import is the
 * piece of this system that decides everyone's pay.
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
        return app(ImportTimelogs::class)->handle($terminal, $path, $reference)[0];
    }

    private function terminal(): Terminal
    {
        $terminal = Terminal::factory()->create();
        $this->withTenant(Agency::findOrFail($terminal->agency_id));

        return $terminal;
    }

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

    public function test_an_alphanumeric_uid_imports(): void
    {
        $terminal = $this->terminal();

        $sync = $this->import($terminal, $this->attlog("A17\t2026-09-01 08:01:23\t0\t1\n"));

        $this->assertSame(1, $sync->accepted);
        $this->assertSame('A17', Timelog::sole()->uid);
    }

    public function test_a_leading_zero_uid_is_not_the_same_person(): void
    {
        $terminal = $this->terminal();
        Enrollment::factory()->on($terminal)->create(['uid' => '7', 'starts' => '2026-01-01']);

        $this->import($terminal, $this->attlog("007\t2026-09-01 08:01:23\t0\t1\n"));

        $this->assertSame('007', Timelog::sole()->uid);
        $this->assertNull(Timelog::sole()->employee_id);
    }

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

    public function test_an_import_does_not_move_the_devices_read_position(): void
    {
        $terminal = $this->terminal();

        $this->import($terminal, $this->attlog("0001\t2026-09-01 08:01:23\t0\t1\n"));

        $terminal->refresh();

        $this->assertNull($terminal->stamp);
        $this->assertNull($terminal->synced_at);
        $this->assertNull($terminal->seen_at);
    }

    public function test_the_device_layout_reads_state_and_mode_past_the_device_column(): void
    {
        $terminal = $this->terminal();

        [$sync] = app(ImportTimelogs::class)->handle(
            $terminal,
            $this->attlog("0001\t2026-09-01 08:01:23\t{$terminal->code}\t1\t4\t0\n"),
            'attlog.dat',
            AttlogParser::LAYOUT_DEVICE,
        );

        $this->assertSame(1, $sync->accepted);
        $this->assertSame(1, Timelog::sole()->state);
        $this->assertSame(4, Timelog::sole()->mode);
    }

    public function test_a_file_recorded_by_another_device_is_refused_whole(): void
    {
        $terminal = Terminal::factory()->create(['code' => '3']);
        $this->withTenant(Agency::findOrFail($terminal->agency_id));

        [$sync] = app(ImportTimelogs::class)->handle(
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
            [$sync] = app(ImportTimelogs::class)->handle(
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

    public function test_a_numeric_device_code_matches_as_a_string(): void
    {
        $terminal = Terminal::factory()->create(['code' => '7']);
        $this->withTenant(Agency::findOrFail($terminal->agency_id));

        [$sync] = app(ImportTimelogs::class)->handle(
            $terminal,
            $this->attlog("0001\t2026-09-01 08:01:23\t7\t0\t1\t0\n"),
            'attlog.dat',
            AttlogParser::LAYOUT_DEVICE,
        );

        $this->assertSame(SyncStatus::Completed, $sync->status);
        $this->assertSame(1, $sync->accepted);
    }

    public function test_a_standard_layout_file_cannot_be_checked_against_the_terminal(): void
    {
        $terminal = Terminal::factory()->create(['code' => '3']);
        $this->withTenant(Agency::findOrFail($terminal->agency_id));

        $sync = $this->import($terminal, $this->attlog("0001\t2026-09-01 08:01:23\t0\t1\n"));

        $this->assertSame(1, $sync->accepted);
        $this->assertSame(0, $sync->rejected);
    }

    public function test_the_counts_are_the_same_whatever_the_chunk_size(): void
    {
        $terminal = $this->terminal();

        $lines = '';

        for ($minute = 0; $minute < 25; $minute++) {
            $lines .= sprintf("0001\t2026-09-01 08:%02d:00\t0\t1\n", $minute);
        }

        [$sync] = app(ImportTimelogs::class)->handle(
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

    public function test_the_endpoint_refuses_a_user_who_may_only_view_terminals(): void
    {
        $terminal = $this->terminal();
        $this->actingAsAgency(Agency::findOrFail($terminal->agency_id), Permission::ViewTerminals);

        $this->post(route('terminals.syncs.store', $terminal), [
            'file' => UploadedFile::fake()->createWithContent('attlog.dat', "0001\t2026-09-01 08:01:23\t0\t1\n"),
        ])->assertForbidden();

        $this->assertSame(0, Timelog::count());
    }

    public function test_the_endpoint_admits_a_platform_superuser(): void
    {
        $terminal = $this->terminal();
        $this->actingAsPlatform(Agency::findOrFail($terminal->agency_id));

        $this->post(route('terminals.syncs.store', $terminal), [
            'file' => UploadedFile::fake()->createWithContent('attlog.dat', "0001\t2026-09-01 08:01:23\t0\t1\n"),
        ])->assertSessionHas('success');

        $this->assertSame(1, Timelog::where('terminal_id', $terminal->id)->count());
    }

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

    public function test_an_import_exposes_the_accepted_employee_and_time_pairs(): void
    {
        $terminal = $this->terminal();
        $enrollment = Enrollment::factory()->on($terminal)->create([
            'uid' => '0001',
            'starts' => '2026-01-01',
        ]);

        [$sync, $pairs] = app(ImportTimelogs::class)->handle(
            $terminal,
            $this->attlog(
                "0001\t2026-09-01 08:01:23\t0\t1\n".
                "9999\t2026-09-01 08:15:00\t0\t1\n"
            ),
            'attlog.dat',
        );

        $this->assertSame(2, $sync->accepted);
        $this->assertCount(2, $pairs);
        $resolved = collect($pairs)->first(fn (array $pair): bool => $pair['employee_id'] === $enrollment->employee_id);
        $orphan = collect($pairs)->first(fn (array $pair): bool => $pair['employee_id'] === null);
        $this->assertNotNull($resolved);
        $this->assertSame('2026-09-01', CarbonImmutable::parse($resolved['time'])->toDateString());
        $this->assertNotNull($orphan);
    }

    public function test_accepted_pairs_collapse_to_one_entry_per_employee_and_date(): void
    {
        $terminal = $this->terminal();
        $enrollment = Enrollment::factory()->on($terminal)->create([
            'uid' => '0001',
            'starts' => '2026-01-01',
        ]);

        $lines = '';

        for ($minute = 0; $minute < 25; $minute++) {
            $lines .= sprintf("0001\t2026-09-01 08:%02d:00\t0\t1\n", $minute);
        }

        [$sync, $pairs] = app(ImportTimelogs::class)->handle(
            $terminal,
            $this->attlog($lines),
            'attlog.dat',
        );

        $this->assertSame(25, $sync->accepted);
        $this->assertCount(1, $pairs);
        $this->assertSame($enrollment->employee_id, $pairs[0]['employee_id']);
        $this->assertSame('2026-09-01', $pairs[0]['time']);
    }

    public function test_the_action_does_not_dispatch_a_recompute(): void
    {
        Queue::fake([RecomputeWorkdays::class]);
        $terminal = $this->terminal();
        Enrollment::factory()->on($terminal)->create(['uid' => '0001', 'starts' => '2026-01-01']);

        $this->import($terminal, $this->attlog("0001\t2026-09-01 08:01:23\t0\t1\n"));

        Queue::assertNotPushed(RecomputeWorkdays::class);
    }

    public function test_the_artisan_command_dispatches_a_recompute_for_an_enrolled_punch(): void
    {
        Queue::fake([RecomputeWorkdays::class]);
        $terminal = $this->terminal();
        $enrollment = Enrollment::factory()->on($terminal)->create([
            'uid' => '0001',
            'starts' => '2026-01-01',
        ]);
        $path = $this->attlog("0001\t2026-09-01 08:01:23\t0\t1\n");

        $this->artisan("timelogs:import {$terminal->code} {$path}")->assertSuccessful();

        Queue::assertPushed(RecomputeWorkdays::class, function (RecomputeWorkdays $job) use ($enrollment): bool {
            return $job->employeeId === $enrollment->employee_id
                && $job->from === '2026-08-29'
                && $job->to === '2026-09-01';
        });
    }

    public function test_the_artisan_command_does_not_recompute_duplicates(): void
    {
        Queue::fake([RecomputeWorkdays::class]);
        $terminal = $this->terminal();
        Enrollment::factory()->on($terminal)->create(['uid' => '0001', 'starts' => '2026-01-01']);
        $path = $this->attlog("0001\t2026-09-01 08:01:23\t0\t1\n");

        $this->artisan("timelogs:import {$terminal->code} {$path}")->assertSuccessful();
        Queue::assertPushedTimes(RecomputeWorkdays::class, 1);

        $this->artisan("timelogs:import {$terminal->code} {$path}")->assertSuccessful();
        Queue::assertPushedTimes(RecomputeWorkdays::class, 1);
    }

    public function test_the_terminal_sync_endpoint_dispatches_a_recompute_for_an_enrolled_punch(): void
    {
        Queue::fake([RecomputeWorkdays::class]);
        $terminal = $this->terminal();
        $enrollment = Enrollment::factory()->on($terminal)->create([
            'uid' => '0001',
            'starts' => '2026-01-01',
        ]);
        $this->actingAsAgency(Agency::findOrFail($terminal->agency_id), Permission::ManageTerminals);

        $this->post(route('terminals.syncs.store', $terminal), [
            'file' => UploadedFile::fake()->createWithContent('attlog.dat', "0001\t2026-09-01 08:01:23\t0\t1\n"),
        ])->assertSessionHas('success');

        Queue::assertPushed(RecomputeWorkdays::class, function (RecomputeWorkdays $job) use ($enrollment): bool {
            return $job->employeeId === $enrollment->employee_id
                && $job->from === '2026-08-29'
                && $job->to === '2026-09-01';
        });
    }
}
