<?php

namespace Tests\Feature\Models;

use App\Models\Sync;
use App\Models\Terminal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
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

    public function test_started_at_is_required(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['started_at' => null])
        ));
    }

    public function test_trigger_must_be_a_known_value(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['trigger' => 'cron'])
        ));
    }

    public function test_status_must_be_a_known_value(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['status' => 'partial'])
        ));
    }

    public function test_a_run_cannot_finish_before_it_started(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['finished_at' => '2026-09-01 07:59:00'])
        ));
    }

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

    public function test_a_freshly_opened_run_is_all_zeroes(): void
    {
        $sync = Sync::factory()->create();

        $this->assertSame(0, $sync->received);
        $this->assertSame(0, $sync->accepted);
        $this->assertSame(0, $sync->duplicates);
        $this->assertSame(0, $sync->rejected);

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

    public function test_a_span_cannot_have_only_a_lower_bound(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['earliest' => '2026-09-01 07:58:00', 'latest' => null])
        ));
    }

    public function test_a_span_cannot_have_only_an_upper_bound(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('syncs')->insert(
            $this->syncRow($sync, ['earliest' => null, 'latest' => '2026-09-01 17:04:00'])
        ));
    }

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

    public function test_terminal_must_share_the_syncs_agency(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Sync::factory()->create(['terminal_id' => $terminal->id]));
    }

    public function test_terminal_with_a_sync_cannot_be_deleted(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('terminals')->where('id', $sync->terminal_id)->delete());
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'syncs_id_agency_id_unique'"));
    }

    public function test_id_and_terminal_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'syncs_id_terminal_id_unique'"));
    }
}
