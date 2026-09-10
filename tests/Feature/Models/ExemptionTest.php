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
    /** @return array<string, mixed> */
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

    /**
     * employee_id NOT NULL, and one nullable column would defeat the tenancy
     * guarantee too: MATCH SIMPLE skips a paired FK entirely once a
     * referencing column is null.
     */
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

    /**
     * `approved_at` NOT NULL because v1 sets it on entry (rule 5) and filing
     * workflows are phase 2: until they exist, an unapproved exemption is a
     * state the deriver has no rule for. Relaxing this later is a safe
     * migration.
     */
    public function test_approved_at_is_required(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['approved_at' => null])
        ));
    }

    /** exemptions_type_valid — the seven of decision 19 and nothing else. */
    public function test_type_must_be_one_of_the_seven(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['type' => 'sick'])
        ));
    }

    /** Ruling P4: the primary key masks the pair, so assert the catalog. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'exemptions_id_agency_id_unique'"));
    }

    /**
     * The target `workdays.exemption_id` pairs against in Milestone 6, so
     * that a stamped exemption is provably that employee's own. Nothing
     * references it yet — exactly as `deployments`' own pair predated its use
     * — which is why only the catalog can be asserted.
     */
    public function test_id_and_employee_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'exemptions_id_employee_id_unique'"));
    }

    /** exemptions_employee_id_agency_id_foreign, insert side. */
    public function test_employee_must_share_the_exemptions_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Exemption::factory()->create(['employee_id' => $employee->id]));
    }

    /**
     * Same FK, delete side. A raw DELETE, not $employee->delete(): employees
     * are soft deleted, so the Eloquent call is an UPDATE the FK never sees.
     */
    public function test_employee_with_an_exemption_cannot_be_hard_deleted(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $exemption->employee_id)->delete());
    }

    /** exemptions_user_id_foreign, insert side. */
    public function test_entering_user_must_exist(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['user_id' => (string) Str::ulid()])
        ));
    }

    /** Same FK, delete side. */
    public function test_user_who_entered_an_exemption_cannot_be_deleted(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('users')->where('id', $exemption->user_id)->delete());
    }

    /**
     * The same single-column-FK choice suspensions.user_id makes, and for the
     * same reason: a platform superuser who has entered the agency does the
     * data entry, and pairing user_id against agency_id would refuse them.
     */
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

    /**
     * `until` NOT NULL, which is decision 38 and the change that removed a
     * trap rather than adding a rule. An earlier draft made it nullable with
     * null meaning "one day"; `daterange(date, until, '[]')` with a null
     * upper bound is **unbounded above**, so any future query or exclusion
     * constraint built the way the rest of this schema builds ranges would
     * have read a two-hour pass slip as excusing every day thereafter.
     */
    public function test_until_is_required(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses(
            '23502',
            fn () => DB::table('exemptions')->insert($this->exemptionRow($exemption, ['until' => null])),
            'column "until"',
        );
    }

    /**
     * And the payoff, asserted in the schema's own idiom: a one-day exemption
     * built as a daterange the way every exclusion constraint here builds one
     * is **bounded**, and contains exactly its own day.
     *
     * This is the test the nullable version could not have passed. It is
     * written in SQL rather than through the model deliberately — the hazard
     * was never the Eloquent scope, which was correct either way, but SQL
     * nobody had written yet.
     */
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

    /** exemptions_span_ordered: the last day cannot precede the first. */
    public function test_a_span_cannot_end_before_it_starts(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['until' => $exemption->date->subDay()->toDateString()])
        ), 'exemptions_span_ordered');
    }

    /** exemptions_hours_paired. */
    public function test_a_half_set_window_is_refused(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['starts' => '10:00:00', 'ends' => null])
        ));
    }

    /** exemptions_hours_ordered. Both set, so only this CHECK can fail. */
    public function test_a_window_cannot_end_when_or_before_it_starts(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['starts' => '10:00:00', 'ends' => '10:00:00'])
        ));
    }

    /**
     * exemptions_span_is_whole_days. A 10:00–14:00 window repeated across 105
     * days of maternity leave is not something anyone means, and a row saying
     * it would make the deriver excuse four hours a day of a continuous leave
     * — under-excusing by an entire statutory entitlement.
     */
    public function test_a_multi_day_exemption_cannot_carry_hours(): void
    {
        $exemption = Exemption::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('exemptions')->insert($this->exemptionRow($exemption, [
            'until' => $exemption->date->addDays(30)->toDateString(),
            'starts' => '10:00:00',
            'ends' => '14:00:00',
        ])));
    }

    /**
     * Decision 37, the case it exists for. RA 11210's 105 continuous days for
     * a live birth is **one row**, not 105, and because the period is
     * continuous it covers every day inside it — Saturdays, Sundays and rest
     * days included, with no rows invented for them.
     *
     * The last day is inclusive, so a leave beginning 1 September runs to
     * 14 December: `date + 104`.
     */
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

    /**
     * A single-day exemption covers exactly its day. Trivially true under
     * decision 38 and emphatically not so under its first draft, where a null
     * `until` read as an open end to anything building a range — see
     * test_a_one_day_exemption_is_a_bounded_range.
     */
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

    /**
     * No exclusion constraint over the employee and the range, deliberately:
     * a two-hour pass in the morning and a CTO in the afternoon are one
     * ordinary day. Milestone 6 stamps one workdays.exemption_id per day and
     * picks by precedence — a deriver rule, not a schema one.
     *
     * Asserts the fetched rows rather than a count, for the reason
     * SuspensionTest does: a count cannot detect a reader capped at one row.
     */
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

    /**
     * `Exemption::excused()` is the name decision 19 and 05-calendar.md rule 7
     * give as "the one place that decides"; it delegates to the enum so the
     * truth table sits with the cases. False only for `personal`.
     */
    public function test_only_a_personal_slip_excuses_nothing(): void
    {
        $this->assertFalse(Exemption::factory()->personal()->make()->excused());
        $this->assertTrue(Exemption::factory()->make()->excused());
    }

    /**
     * The two shape predicates, which are each other's negation by
     * exemptions_span_is_whole_days: a multi-day exemption is whole days, and
     * an hours exemption is one day.
     */
    public function test_the_shape_predicates_read_the_nullable_columns(): void
    {
        $continuous = Exemption::factory()->spanning(105)->create();
        $slip = Exemption::factory()->hours()->create();

        $this->assertTrue($continuous->spansDays());
        $this->assertTrue($continuous->wholeDay());

        $this->assertFalse($slip->spansDays());
        $this->assertFalse($slip->wholeDay());
    }

    /**
     * actor_of_agency. The single-column `user_id` FK is wider than the
     * intent it serves: it exists so a platform superuser who has entered the
     * agency can do the data entry, not so an ordinary user of some third
     * agency can be recorded as having entered this. No foreign key can say
     * "this agency **or** the platform one", so a trigger does — the same
     * division of labour `origin_is_platform()` makes for the other
     * deliberate cross-agency pointer in the schema.
     *
     * Found by an adversarial review on 2026-09-11, which noticed the FK
     * permitted what the application never produces.
     */
    public function test_the_recording_user_cannot_belong_to_a_third_agency(): void
    {
        $exemption = Exemption::factory()->create();
        $stranger = User::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('exemptions')->insert(
            $this->exemptionRow($exemption, ['user_id' => $stranger->id])
        ));
    }
}
