<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Suspension;
use App\Models\User;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuspensionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function suspensionRow(Suspension $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'workgroup_id' => $like->workgroup_id,
            'date' => $like->date->toDateString(),
            'starts' => null,
            'ends' => null,
            'reason' => 'Flooding',
            'reference' => 'Memorandum No. 12',
            'user_id' => $like->user_id,
            'declared_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_suspension_needs_an_agency(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['agency_id' => null])
        ));
    }

    public function test_date_is_required(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['date' => null])
        ));
    }

    public function test_reason_is_required(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['reason' => null])
        ));
    }

    public function test_declaring_user_is_required(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['user_id' => null])
        ));
    }

    public function test_declared_at_is_required(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['declared_at' => null])
        ));
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'suspensions_id_agency_id_unique'"));
    }

    public function test_a_half_set_window_is_refused(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['starts' => '12:00:00', 'ends' => null])
        ));
    }

    public function test_an_end_without_a_start_is_refused(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['starts' => null, 'ends' => '17:00:00'])
        ));
    }

    public function test_a_window_cannot_end_when_or_before_it_starts(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['starts' => '12:00:00', 'ends' => '12:00:00'])
        ));
    }

    public function test_workgroup_must_share_the_suspensions_agency(): void
    {
        $workgroup = Workgroup::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Suspension::factory()->create(['workgroup_id' => $workgroup->id]));
    }

    public function test_workgroup_with_a_suspension_cannot_be_hard_deleted(): void
    {
        $workgroup = Workgroup::factory()->create();
        $suspension = Suspension::factory()->forWorkgroup($workgroup)->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('workgroups')->where('id', $suspension->workgroup_id)->delete());
    }

    public function test_declaring_user_must_exist(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['user_id' => (string) Str::ulid()])
        ));
    }

    public function test_user_who_declared_a_suspension_cannot_be_deleted(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('users')->where('id', $suspension->user_id)->delete());
    }

    public function test_an_agency_wide_suspension_names_no_workgroup(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertNull($suspension->workgroup_id);
        $this->assertDatabaseHas('suspensions', ['id' => $suspension->id, 'workgroup_id' => null]);
    }

    public function test_the_declaring_user_may_belong_to_another_agency(): void
    {
        $agency = Agency::factory()->create();
        $superuser = User::factory()->platform()->create();

        $suspension = Suspension::factory()->create([
            'agency_id' => $agency->id,
            'user_id' => $superuser->id,
        ]);

        $this->assertSame($this->platform()->id, $suspension->user->agency_id);
        $this->assertSame($agency->id, $suspension->agency_id);
    }

    public function test_a_date_may_carry_more_than_one_suspension(): void
    {
        $workgroup = Workgroup::factory()->create();
        $this->withTenant(Agency::findOrFail($workgroup->agency_id));
        $date = CarbonImmutable::parse('2026-09-15');

        Suspension::factory()->forWorkgroup($workgroup)->partial('08:00:00', '12:00:00')
            ->create(['date' => $date, 'reason' => 'Brownout, morning']);
        Suspension::factory()->forWorkgroup($workgroup)->partial('13:00:00', '17:00:00')
            ->create(['date' => $date, 'reason' => 'Brownout, afternoon']);

        $this->assertSame(
            ['Brownout, afternoon', 'Brownout, morning'],
            Suspension::covering($date)->orderBy('reason')->pluck('reason')->all(),
        );
    }

    public function test_a_whole_day_suspension_names_no_hours(): void
    {
        $whole = Suspension::factory()->create();
        $partial = Suspension::factory()->partial()->create();

        $this->assertTrue($whole->wholeDay());
        $this->assertFalse($partial->wholeDay());
    }

    public function test_the_recording_user_cannot_belong_to_a_third_agency(): void
    {
        $suspension = Suspension::factory()->create();
        $stranger = User::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['user_id' => $stranger->id])
        ));
    }
}
