<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function shiftRow(string $agency, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $agency,
            'name' => 'Standard '.Str::random(6),
            'slots' => '[{"in":"08:00","out":"12:00","window":[-240,180]}]',
            'required' => 480,
            'flex' => 0,
            'remote' => false,
            'trust' => false,
            'color' => 1,
            'origin_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_shift_needs_an_agency(): void
    {
        $this->assertDatabaseRefuses('23502', fn () => DB::table('shifts')->insert(
            $this->shiftRow(Agency::factory()->create()->id, ['agency_id' => null])
        ));
    }

    public function test_agency_with_shifts_cannot_be_deleted(): void
    {
        $shift = Shift::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('agencies')->where('id', $shift->agency_id)->delete());
    }

    public function test_name_is_unique_within_an_agency(): void
    {
        $shift = Shift::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('shifts')->insert(
            $this->shiftRow($shift->agency_id, ['name' => $shift->name, 'color' => 2])
        ));

        // The same name under a different agency: accepted.
        $elsewhere = Shift::factory()->create(['name' => $shift->name]);
        $this->assertDatabaseHas('shifts', ['id' => $elsewhere->id, 'name' => $shift->name]);
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'shifts_id_agency_id_unique'"));
    }

    public function test_required_minutes_cannot_be_negative(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => Shift::factory()->create(['required' => -1]));
    }

    public function test_flex_cannot_be_negative(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => Shift::factory()->create(['flex' => -1]));
    }

    public function test_color_must_sit_in_the_eight_step_ramp(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => Shift::factory()->create(['color' => 0]));
        $this->assertDatabaseRefuses('23514', fn () => Shift::factory()->create(['color' => 9]));

        foreach ([1, 8] as $edge) {
            $accepted = Shift::factory()->create(['color' => $edge]);
            $this->assertDatabaseHas('shifts', ['id' => $accepted->id, 'color' => $edge]);
        }
    }

    public function test_slot_shape_is_enforced_by_the_database(): void
    {
        $malformed = [
            'out before in' => [['in' => '12:00', 'out' => '08:00', 'window' => [-60, 60]]],
            'past the 72:00 cap' => [['in' => '08:00', 'out' => '73:00', 'window' => [-60, 60]]],
            'pairs out of order' => [
                ['in' => '13:00', 'out' => '17:00', 'window' => [-60, 60]],
                ['in' => '08:00', 'out' => '12:00', 'window' => [-60, 60]],
            ],
            'overlapping pairs' => [
                ['in' => '08:00', 'out' => '13:00', 'window' => [-60, 60]],
                ['in' => '12:00', 'out' => '17:00', 'window' => [-60, 60]],
            ],
            'minute past 59' => [['in' => '08:60', 'out' => '12:00', 'window' => [-60, 60]]],
            'window opens after the in' => [['in' => '08:00', 'out' => '12:00', 'window' => [60, 60]]],
            'window closes before the out' => [['in' => '08:00', 'out' => '12:00', 'window' => [-60, -60]]],
            'window of one value' => [['in' => '08:00', 'out' => '12:00', 'window' => [-60]]],
            'no window at all' => [['in' => '08:00', 'out' => '12:00']],
            'negative grace' => [['in' => '08:00', 'out' => '12:00', 'window' => [-60, 60], 'grace' => -5]],
            'unparseable time' => [['in' => 'noon', 'out' => '12:00', 'window' => [-60, 60]]],
            'array of strings' => ['08:00'],
        ];

        foreach ($malformed as $label => $slots) {
            $this->assertDatabaseRefuses('23514', fn () => Shift::factory()->create(['name' => $label, 'slots' => $slots]));
        }

        // Every worked example in 04-scheduling.md, accepted.
        $accepted = [
            'Off' => [],
            'standard 8-5' => [
                ['in' => '08:00', 'out' => '12:00', 'window' => [-240, 180]],
                ['in' => '13:00', 'out' => '17:00', 'window' => [-120, 300]],
            ],
            'night into the next day' => [['in' => '22:00', 'out' => '30:00', 'window' => [-120, 120]]],
            'twelve-hour night' => [['in' => '18:00', 'out' => '30:00', 'window' => [-120, 120]]],
            'twenty-four-hour duty' => [['in' => '08:00', 'out' => '32:00', 'window' => [-60, 60]]],
            'punched break across midnight' => [
                ['in' => '22:00', 'out' => '26:00', 'window' => [-120, 120]],
                ['in' => '26:00', 'out' => '30:00', 'window' => [-120, 120]],
            ],
            'grace of ten minutes' => [['in' => '08:00', 'out' => '12:00', 'window' => [-60, 60], 'grace' => 10]],
        ];

        foreach ($accepted as $label => $slots) {
            $shift = Shift::factory()->create([
                'name' => $label,
                'slots' => $slots,
                'required' => $slots === [] ? 0 : 480,
                'flex' => 0,
            ]);
            $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'name' => $label]);
        }
    }

    public function test_a_remote_shift_cannot_carry_slots(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => Shift::factory()->create([
            'remote' => true,
            'slots' => [['in' => '08:00', 'out' => '12:00', 'window' => [-60, 60]]],
        ]));
    }

    public function test_an_off_shift_credits_nothing_but_a_remote_one_may(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => Shift::factory()->create([
            'slots' => [], 'remote' => false, 'required' => 480, 'flex' => 0,
        ]));

        $remote = Shift::factory()->remote()->create(['required' => 480]);
        $this->assertDatabaseHas('shifts', ['id' => $remote->id, 'required' => 480]);
    }

    public function test_a_shift_without_slots_cannot_have_flex(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => Shift::factory()->create([
            'slots' => [], 'required' => 0, 'flex' => 60,
        ]));
    }

    public function test_an_origin_must_be_a_platform_owned_shift(): void
    {
        $private = Shift::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => Shift::factory()->copiedFrom($private)->create());

        $default = Shift::factory()->create(['agency_id' => $this->platform()->id]);
        $copy = Shift::factory()->copiedFrom($default)->create();

        $this->assertDatabaseHas('shifts', ['id' => $copy->id, 'origin_id' => $default->id]);
    }

    public function test_an_origin_that_does_not_exist_is_refused_by_the_foreign_key(): void
    {
        $this->assertDatabaseRefuses('23503', fn () => Shift::factory()->create(['origin_id' => (string) Str::ulid()]));
    }

    public function test_deleting_an_origin_clears_the_copies_pointer(): void
    {
        $default = Shift::factory()->create(['agency_id' => $this->platform()->id]);
        $copy = Shift::factory()->copiedFrom($default)->create();

        DB::table('shifts')->where('id', $default->id)->delete();

        $this->assertDatabaseHas('shifts', ['id' => $copy->id, 'origin_id' => null]);
    }

    public function test_the_platform_agency_owns_the_default_shifts(): void
    {
        $default = Shift::factory()->create(['agency_id' => $this->platform()->id]);

        $this->assertDatabaseHas('shifts', ['id' => $default->id, 'agency_id' => $this->platform()->id]);
    }
}
