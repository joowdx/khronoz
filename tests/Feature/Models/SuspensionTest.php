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

/**
 * No agency_not_platform test and no such trigger: a suspension needs no
 * employee or workgroup — an agency-wide one has neither — so unlike
 * `deployments` and `rosters` the platform row is reachable here. It is also
 * harmless: nothing operational hangs under the platform agency
 * (07-constraints.md), so a suspension declared there suspends nobody.
 *
 * Whose day a suspension excuses is not a constraint and is not tested here;
 * it is the operative-deployment resolution of 05-calendar.md rule 3, which
 * has its own tests.
 */
class SuspensionTest extends TestCase
{
    /** @return array<string, mixed> */
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

    /** The operative justification, and it prints on the DTR. */
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

    /** Ruling P4: the primary key masks the pair, so assert the catalog. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'suspensions_id_agency_id_unique'"));
    }

    /**
     * suspensions_hours_paired. "Suspended from noon until nothing" is not a
     * declaration anyone can act on, and the deriver would have to guess an
     * end. Constructed so only this CHECK can fail: with `ends` null,
     * suspensions_hours_ordered evaluates `NULL > starts` to NULL, which a
     * CHECK accepts.
     */
    public function test_a_half_set_window_is_refused(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['starts' => '12:00:00', 'ends' => null])
        ));
    }

    /** The same CHECK from the other side: an end with no start. */
    public function test_an_end_without_a_start_is_refused(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['starts' => null, 'ends' => '17:00:00'])
        ));
    }

    /**
     * suspensions_hours_ordered, and strictly greater rather than the `>=`
     * every date range in this schema uses: a one-day date range is a real
     * thing, a zero-length time window suspends nothing. Both columns are set
     * here so suspensions_hours_paired passes and only this CHECK can fail.
     */
    public function test_a_window_cannot_end_when_or_before_it_starts(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['starts' => '12:00:00', 'ends' => '12:00:00'])
        ));
    }

    /** suspensions_workgroup_id_agency_id_foreign, insert side. */
    public function test_workgroup_must_share_the_suspensions_agency(): void
    {
        $workgroup = Workgroup::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Suspension::factory()->create(['workgroup_id' => $workgroup->id]));
    }

    /**
     * Same FK, delete side. A raw DELETE, not $workgroup->delete(): workgroups
     * are soft deleted, so the Eloquent call is an UPDATE the FK never sees
     * and the test would assert nothing while passing.
     */
    public function test_workgroup_with_a_suspension_cannot_be_hard_deleted(): void
    {
        $workgroup = Workgroup::factory()->create();
        $suspension = Suspension::factory()->forWorkgroup($workgroup)->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('workgroups')->where('id', $suspension->workgroup_id)->delete());
    }

    /** suspensions_user_id_foreign, insert side. */
    public function test_declaring_user_must_exist(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['user_id' => (string) Str::ulid()])
        ));
    }

    /** Same FK, delete side. Users are hard deleted, so an Eloquent call would do. */
    public function test_user_who_declared_a_suspension_cannot_be_deleted(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('users')->where('id', $suspension->user_id)->delete());
    }

    /**
     * `workgroup_id` null is agency-wide, and needs no sentinel row: MATCH
     * SIMPLE skips the paired FK entirely once a referencing column is null.
     * `agency_id` still carries its own FK, so the row is still proven to
     * belong to a real agency.
     */
    public function test_an_agency_wide_suspension_names_no_workgroup(): void
    {
        $suspension = Suspension::factory()->create();

        $this->assertNull($suspension->workgroup_id);
        $this->assertDatabaseHas('suspensions', ['id' => $suspension->id, 'workgroup_id' => null]);
    }

    /**
     * Why `user_id` is a single-column FK where `attestations.user_id` is
     * paired against (id, agency_id): a signature must come from inside the
     * agency, but a **declaration** may be entered by a platform superuser who
     * has entered the agency, and whose own agency_id is the platform row.
     * Pairing here would refuse exactly the person doing the data entry.
     */
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

    /**
     * No uniqueness or exclusion over (agency_id, workgroup_id, date), and
     * deliberately: a morning window and an afternoon one on the same date are
     * ordinary, as is a division extending an agency-wide closure for its own
     * reason. The deriver takes the union.
     *
     * Two details of this test's construction are load-bearing, and mutation
     * testing is what found both.
     *
     * The suspensions are scoped to a **workgroup** rather than agency-wide,
     * because a `UNIQUE (agency_id, workgroup_id, date)` added by mistake
     * would not bite on two agency-wide rows at all: `workgroup_id` is null
     * there, and a UNIQUE index treats nulls as distinct — the same
     * NULLS DISTINCT fact that makes `holidays.name NOT NULL` load-bearing.
     * The agency-wide version of this test passed against a constraint that
     * would have refused every real pair.
     *
     * And it asserts the **fetched rows**, not `->count()`. A `count()` cannot
     * detect a reader capped at one row: `SELECT count(*) … LIMIT 1` applies
     * the limit to the single aggregate row and still answers 2, so the
     * mutation that put `->limit(1)` inside `covering()` survived a counting
     * assertion. That mutation is precisely the ->first() defect this table
     * shares with `holidays`, so the assertion has to look at the set.
     */
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

    /** wholeDay() reads the nullable pair, which is the whole discriminator. */
    public function test_a_whole_day_suspension_names_no_hours(): void
    {
        $whole = Suspension::factory()->create();
        $partial = Suspension::factory()->partial()->create();

        $this->assertTrue($whole->wholeDay());
        $this->assertFalse($partial->wholeDay());
    }

    /**
     * actor_of_agency. The single-column `user_id` FK is wider than the
     * intent it serves: it exists so a platform superuser who has entered the
     * agency can do the data entry, not so an ordinary user of some third
     * agency can be recorded as having declared this. No foreign key can say
     * "this agency **or** the platform one", so a trigger does — the same
     * division of labour `origin_is_platform()` makes for the other
     * deliberate cross-agency pointer in the schema.
     *
     * Found by an adversarial review on 2026-09-11, which noticed the FK
     * permitted what the application never produces.
     */
    public function test_the_recording_user_cannot_belong_to_a_third_agency(): void
    {
        $suspension = Suspension::factory()->create();
        $stranger = User::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('suspensions')->insert(
            $this->suspensionRow($suspension, ['user_id' => $stranger->id])
        ));
    }
}
