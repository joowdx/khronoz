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

class TerminalTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
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

    public function test_kind_must_be_a_known_value(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['kind' => 'tablet'])
        ));
    }

    public function test_protocol_must_be_a_known_value(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['protocol' => 'ftp'])
        ));
    }

    public function test_two_terminals_of_one_agency_cannot_share_a_code(): void
    {
        $terminal = Terminal::factory()->create();

        DB::table('terminals')->insert($this->terminalRow($terminal, ['code' => $terminal->code.'9']));

        $this->assertDatabaseRefuses('23505', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['code' => $terminal->code])
        ));
    }

    public function test_two_agencies_may_use_the_same_code(): void
    {
        $mine = Terminal::factory()->create(['code' => '1']);
        $theirs = Terminal::factory()->create(['code' => '1']);

        $this->assertNotSame($mine->agency_id, $theirs->agency_id);
        $this->assertSame('1', $mine->code);
        $this->assertSame('1', $theirs->code);
    }

    public function test_two_terminals_cannot_share_a_serial(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('terminals')->insert(
            $this->terminalRow($terminal, ['serial' => $terminal->serial])
        ));
    }

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

        $this->assertStringContainsString(
            'WHERE (serial IS NOT NULL)',
            DB::selectOne("select indexdef from pg_indexes where indexname = 'terminals_serial'")->indexdef,
        );
    }

    public function test_workgroup_must_share_the_terminals_agency(): void
    {
        $workgroup = Workgroup::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Terminal::factory()->create(['workgroup_id' => $workgroup->id]));
    }

    public function test_workgroup_with_a_terminal_cannot_be_hard_deleted(): void
    {
        $workgroup = Workgroup::factory()->create();
        $terminal = Terminal::factory()->forWorkgroup($workgroup)->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('workgroups')->where('id', $terminal->workgroup_id)->delete());
    }

    public function test_an_agency_wide_terminal_names_no_workgroup(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertNull($terminal->workgroup_id);
        $this->assertDatabaseHas('terminals', ['id' => $terminal->id, 'workgroup_id' => null]);
    }

    public function test_agency_with_a_terminal_cannot_be_deleted(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('agencies')->where('id', $terminal->agency_id)->delete());
    }

    public function test_a_terminal_cannot_belong_to_the_platform_agency(): void
    {
        $platform = $this->platform();

        $this->assertDatabaseRefuses('P0001', fn () => Terminal::factory()->create(['agency_id' => $platform->id]));

        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('terminals')->where('id', $terminal->id)->update(['agency_id' => $platform->id]));
    }

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

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'terminals_id_agency_id_unique'"));
    }

    public function test_the_comm_key_is_not_readable_as_plaintext(): void
    {
        $terminal = Terminal::factory()->networked()->create(['secret' => 'comm-key-424242']);

        $stored = DB::table('terminals')->where('id', $terminal->id)->value('secret');

        $this->assertNotSame('comm-key-424242', $stored);
        $this->assertStringNotContainsString('424242', (string) $stored);
        $this->assertSame('comm-key-424242', $terminal->fresh()->secret);
    }
}
