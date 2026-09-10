<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * No agency_not_platform test, and deliberately no such trigger: the platform
 * agency is exactly where national holidays live, the same arrangement
 * `shifts` and `schedules` have (07-constraints.md, "Global rows belong to the
 * platform agency"). `test_a_national_holiday_belongs_to_the_platform_agency`
 * asserts the positive instead.
 *
 * The scope this table reads under — `agency_id IN (own, platform)`, the only
 * one in the schema — is tested in tests/Feature/Tenancy/AgencyOrPlatformScopeTest.
 */
class HolidayTest extends TestCase
{
    /** @return array<string, mixed> */
    private function holidayRow(Holiday $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'date' => $like->date->toDateString(),
            'name' => 'Holiday '.Str::random(6),
            'type' => 'regular',
            'reference' => 'Proclamation No. 727',
            'declared_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_holiday_needs_an_agency(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('holidays')->insert(
            $this->holidayRow($holiday, ['agency_id' => null])
        ));
    }

    public function test_date_is_required(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('holidays')->insert(
            $this->holidayRow($holiday, ['date' => null])
        ));
    }

    /**
     * `name` NOT NULL is load-bearing rather than tidiness: it is the third
     * column of holidays_agency_id_date_name_unique, and a UNIQUE index
     * treats nulls as distinct (NULLS DISTINCT, the default), so a nullable
     * name would let unlimited unnamed rows pile up on one date and defeat
     * the only duplicate protection this table has.
     */
    public function test_name_is_required(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('holidays')->insert(
            $this->holidayRow($holiday, ['name' => null])
        ));
    }

    public function test_type_is_required(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('holidays')->insert(
            $this->holidayRow($holiday, ['type' => null])
        ));
    }

    /**
     * `declared_at` NOT NULL because it is prospective (Res. 2600838 §2.5):
     * a null would make "was this declared before the workday?" unanswerable,
     * and the deriver would have to invent a default for a legal boundary.
     */
    public function test_declared_at_is_required(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('holidays')->insert(
            $this->holidayRow($holiday, ['declared_at' => null])
        ));
    }

    /** holidays_type_valid. */
    public function test_type_must_be_a_known_treatment(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('holidays')->insert(
            $this->holidayRow($holiday, ['type' => 'floating'])
        ));
    }

    /** Ruling P4: the primary key masks the pair, so assert the catalog. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'holidays_id_agency_id_unique'"));
    }

    /** holidays_agency_id_foreign, insert side. */
    public function test_agency_must_exist(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('holidays')->insert(
            $this->holidayRow($holiday, ['agency_id' => (string) Str::ulid()])
        ));
    }

    /**
     * Same FK, delete side. A raw DELETE is not needed for the usual reason
     * here — `agencies` is not soft-deleted — but the platform row also
     * carries agencies_platform_row, which refuses its deletion with P0001
     * before any FK is consulted, so the agency under test must be an
     * ordinary one.
     */
    public function test_agency_with_a_holiday_cannot_be_deleted(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('agencies')->where('id', $holiday->agency_id)->delete());
    }

    /**
     * The rule this whole table is shaped around (dole-rules.md section I
     * item 6). Eid al-Fitr lands on Bonifacio Day; a city charter day lands
     * on a national special day. Both holidays are owed — DOLE applies the
     * higher rate and the day may attract both premiums — so a schema keeping
     * one row per date would silently discard the more expensive one.
     *
     * `name` is in the unique key for exactly this, and the assertion covers
     * both halves: the database accepts the second row, and `covering()`
     * hands back **both**. A reader that reached for ->first() would pass a
     * test asserting only the first half.
     */
    public function test_two_holidays_may_fall_on_one_date(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $date = CarbonImmutable::parse('2026-11-30');

        Holiday::factory()->create(['agency_id' => $agency->id, 'date' => $date, 'name' => 'Bonifacio Day']);
        Holiday::factory()->create(['agency_id' => $agency->id, 'date' => $date, 'name' => 'Eid al-Fitr']);

        $this->assertSame(
            ['Bonifacio Day', 'Eid al-Fitr'],
            Holiday::covering($date)->orderBy('name')->pluck('name')->all(),
        );
    }

    /**
     * holidays_agency_id_date_name_unique. The other side of the rule above:
     * permitting two holidays on one date must not mean permitting the same
     * one twice.
     */
    public function test_the_same_holiday_cannot_be_recorded_twice_on_one_date(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('holidays')->insert(
            $this->holidayRow($holiday, ['name' => $holiday->name])
        ));
    }

    /**
     * Two agencies may each declare a holiday of the same name on the same
     * date — the unique key is per agency, and a charter day shared by two
     * city governments is one holiday to each of them.
     */
    public function test_two_agencies_may_declare_the_same_holiday(): void
    {
        [$mine, $theirs] = Agency::factory()->count(2)->create();

        Holiday::factory()->create(['agency_id' => $mine->id, 'date' => '2026-03-01', 'name' => 'Charter Day']);
        Holiday::factory()->create(['agency_id' => $theirs->id, 'date' => '2026-03-01', 'name' => 'Charter Day']);

        $this->assertSame(2, DB::table('holidays')->where('name', 'Charter Day')->count());
    }

    /**
     * The positive form of the absent agency_not_platform trigger: unlike
     * `employees`, `workgroups` and `teams`, this table accepts the platform
     * agency, because that is where a national holiday lives.
     */
    public function test_a_national_holiday_belongs_to_the_platform_agency(): void
    {
        $holiday = Holiday::factory()->national()->create();

        $this->assertSame($this->platform()->id, $holiday->agency_id);
    }

    /**
     * The same state-closure trap the two dates_ordered factories carried,
     * with no constraint standing behind it: `holidays` ties `declared_at` to
     * nothing, so an eagerly computed value produces a *silently wrong* row
     * rather than a refusal. declaredAfter() means declared retroactively, and
     * reading the definition's default `date` instead of the caller's turned
     * it into a declaration sixty-odd days early — the exact inverse of the
     * state's meaning, and why this is a value assertion rather than an
     * assertDatabaseRefuses.
     */
    public function test_declared_after_is_two_days_after_a_date_the_caller_overrides(): void
    {
        $date = CarbonImmutable::today()->addYears(5);

        $holiday = Holiday::factory()->declaredAfter()->create(['date' => $date->toDateString()]);

        $this->assertSame($date->addDays(2)->toDateString(), $holiday->declared_at->toDateString());
    }
}
