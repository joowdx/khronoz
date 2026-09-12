<?php

namespace Tests\Feature\Models;

use App\Enums\ExemptionType;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * No agency_not_platform test and no such trigger: an exemption needs an
 * employee, and `employees` refuses the platform agency already — the reason
 * `deployments` and `rosters` have none either.
 */
class ExemptionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function exemptionRow(Exemption $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'employee_id' => $like->employee_id,
            'date' => $like->date->toDateString(),
            'until' => $like->date->toDateString(),
            'type' => 'leave',
            'starts' => null,
            'ends' => null,
            'reference' => 'Application No. 123',
            'remarks' => null,
            'user_id' => $like->user_id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_exemption_needs_an_agency(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['agency_id' => null])
        ));
    }

    public function test_employee_is_required(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['employee_id' => null])
        ));
    }

    public function test_date_is_required(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['date' => null])
        ));
    }

    public function test_type_is_required(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['type' => null])
        ));
    }

    public function test_entering_user_is_required(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['user_id' => null])
        ));
    }

    public function test_approved_at_is_required(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['approved_at' => null])
        ));
    }

    public function test_type_must_be_one_of_the_seven(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['type' => 'sick'])
        ));
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'exemptions_id_agency_id_unique'"));
    }

    public function test_id_and_employee_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'exemptions_id_employee_id_unique'"));
    }

    public function test_employee_must_share_the_exemptions_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Exemption::factory()->create(['employee_id' => $employee->id]));
    }

    public function test_employee_with_an_exemption_cannot_be_hard_deleted(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $exemption->employee_id)->delete());
    }

    public function test_entering_user_must_exist(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['user_id' => (string) Str::ulid()])
        ));
    }

    public function test_user_who_entered_an_exemption_cannot_be_deleted(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('users')->where('id', $exemption->user_id)->delete());
    }

    public function test_the_entering_user_may_belong_to_another_agency(): void
    {
        $employee = Employee::factory()->create();
        $superuser = User::factory()->platform()->create();

        $exemption = Exemption::factory()->create([
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
            'user_id' => $superuser->id,
        ]);

        $this->assertSame($this->platform()->id, $exemption->user->agency_id);
    }

    public function test_until_is_required(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses(
            '23502',
            fn () => DB::table('exemptions')->insert($this->exemptionRow($exemption, ['until' => null])),
            'column "until"',
        );
    }

    public function test_a_one_day_exemption_is_a_bounded_range(): void
    {
        $exemption = Exemption::factory()->create(['date' => '2026-09-15']);

        $range = DB::selectOne(
            "select daterange(date, until, '[]')::text as span,
                    upper_inf(daterange(date, until, '[]')) as unbounded,
                    daterange(date, until, '[]') @> '2026-09-16'::date as covers_tomorrow
               from exemptions where id = ?",
            [$exemption->id],
        );

        $this->assertFalse($range->unbounded, 'a one-day exemption must not be an open range');
        $this->assertFalse($range->covers_tomorrow);
        $this->assertSame('[2026-09-15,2026-09-16)', $range->span, 'inclusive of its own day only');
    }

    public function test_a_span_cannot_end_before_it_starts(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['until' => $exemption->date->subDay()->toDateString()])
        ), 'exemptions_span_ordered');
    }

    public function test_a_half_set_window_is_refused(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['starts' => '10:00:00', 'ends' => null])
        ));
    }

    public function test_a_window_cannot_end_when_or_before_it_starts(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['starts' => '10:00:00', 'ends' => '10:00:00'])
        ));
    }

    public function test_a_multi_day_exemption_cannot_carry_hours(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('exemptions')->insert($this->exemptionRow($exemption, [
            'until' => $exemption->date->addDays(30)->toDateString(),
            'starts' => '10:00:00',
            'ends' => '14:00:00',
        ])));
    }

    public function test_a_continuous_leave_is_one_row_covering_every_day_of_it(): void
    {
        $employee = Employee::factory()->create();
        $this->withTenant(Agency::findOrFail($employee->agency_id));
        $first = CarbonImmutable::parse('2026-09-01');

        Exemption::factory()->spanning(105)->create([
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
            'date' => $first,
        ]);

        $this->assertSame(1, Exemption::count(), 'a continuous leave is one authority, not 105 decisions');

        foreach ([0, 5, 6, 52, 104] as $offset) {
            $day = $first->addDays($offset);
            $this->assertSame(
                1,
                Exemption::covering($day)->count(),
                "day {$offset} ({$day->toDateString()}, ".$day->format('l').') is inside the leave',
            );
        }

        $this->assertSame(0, Exemption::covering($first->addDays(105))->count(), 'the day after the last is outside it');
        $this->assertSame(0, Exemption::covering($first->subDay())->count(), 'the day before the first is outside it');
    }

    public function test_a_single_day_exemption_does_not_cover_the_next_day(): void
    {
        $employee = Employee::factory()->create();
        $this->withTenant(Agency::findOrFail($employee->agency_id));
        $date = CarbonImmutable::parse('2026-09-15');

        Exemption::factory()->hours()->create([
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
            'date' => $date,
        ]);

        $this->assertSame(1, Exemption::covering($date)->count());
        $this->assertSame(0, Exemption::covering($date->addDay())->count());
        $this->assertSame(0, Exemption::covering($date->addYears(30))->count());
    }

    public function test_a_day_may_carry_more_than_one_exemption(): void
    {
        $employee = Employee::factory()->create();
        $this->withTenant(Agency::findOrFail($employee->agency_id));
        $date = CarbonImmutable::parse('2026-09-15');

        Exemption::factory()->hours('08:00:00', '10:00:00')->create([
            'agency_id' => $employee->agency_id, 'employee_id' => $employee->id,
            'date' => $date, 'type' => ExemptionType::Pass,
        ]);
        Exemption::factory()->hours('13:00:00', '17:00:00')->create([
            'agency_id' => $employee->agency_id, 'employee_id' => $employee->id,
            'date' => $date, 'type' => ExemptionType::Cto,
        ]);

        $this->assertSame(
            ['cto', 'pass'],
            Exemption::covering($date)->orderBy('type')->pluck('type')->map(fn ($t) => $t->value)->all(),
        );
    }

    public function test_only_a_personal_slip_excuses_nothing(): void
    {
        $this->assertFalse(Exemption::factory()->personal()->make()->excused());
        $this->assertTrue(Exemption::factory()->make()->excused());
    }

    public function test_the_shape_predicates_read_the_nullable_columns(): void
    {
        $continuous = Exemption::factory()->spanning(105)->create();
        $slip = Exemption::factory()->hours()->create();

        $this->assertTrue($continuous->spansDays());
        $this->assertTrue($continuous->wholeDay());

        $this->assertFalse($slip->spansDays());
        $this->assertFalse($slip->wholeDay());
    }

    public function test_the_recording_user_cannot_belong_to_a_third_agency(): void
    {
        $exemption = Exemption::factory()->create();
        $stranger = User::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['user_id' => $stranger->id])
        ));
    }
}
