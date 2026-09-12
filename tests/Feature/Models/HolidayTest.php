<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HolidayTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
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

    public function test_declared_at_is_required(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('holidays')->insert(
            $this->holidayRow($holiday, ['declared_at' => null])
        ));
    }

    public function test_type_must_be_a_known_treatment(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('holidays')->insert(
            $this->holidayRow($holiday, ['type' => 'floating'])
        ));
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'holidays_id_agency_id_unique'"));
    }

    public function test_agency_must_exist(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('holidays')->insert(
            $this->holidayRow($holiday, ['agency_id' => (string) Str::ulid()])
        ));
    }

    public function test_agency_with_a_holiday_cannot_be_deleted(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('agencies')->where('id', $holiday->agency_id)->delete());
    }

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

    public function test_the_same_holiday_cannot_be_recorded_twice_on_one_date(): void
    {
        $holiday = Holiday::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('holidays')->insert(
            $this->holidayRow($holiday, ['name' => $holiday->name])
        ));
    }

    public function test_two_agencies_may_declare_the_same_holiday(): void
    {
        [$mine, $theirs] = Agency::factory()->count(2)->create();

        Holiday::factory()->create(['agency_id' => $mine->id, 'date' => '2026-03-01', 'name' => 'Charter Day']);
        Holiday::factory()->create(['agency_id' => $theirs->id, 'date' => '2026-03-01', 'name' => 'Charter Day']);

        $this->assertSame(2, DB::table('holidays')->where('name', 'Charter Day')->count());
    }

    public function test_a_national_holiday_belongs_to_the_platform_agency(): void
    {
        $holiday = Holiday::factory()->national()->create();

        $this->assertSame($this->platform()->id, $holiday->agency_id);
    }

    public function test_declared_after_is_two_days_after_a_date_the_caller_overrides(): void
    {
        $date = CarbonImmutable::today()->addYears(5);

        $holiday = Holiday::factory()->declaredAfter()->create(['date' => $date->toDateString()]);

        $this->assertSame($date->addDays(2)->toDateString(), $holiday->declared_at->toDateString());
    }

    public function test_a_holiday_of_the_platform_agency_is_national(): void
    {
        $this->assertTrue(Holiday::factory()->national()->create()->national());
    }

    public function test_an_agencys_own_holiday_is_not_national(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);

        $this->assertFalse(Holiday::factory()->create(['agency_id' => $agency->id])->national());
    }
}
