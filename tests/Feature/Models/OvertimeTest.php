<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Employee;
use App\Models\Overtime;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * No agency_not_platform test and no such trigger: an overtime authority needs
 * an employee, and `employees` refuses the platform agency already.
 *
 * `date` never appears in the row helper below — it is generated, and Postgres
 * refuses any supplied value for a generated column. That refusal has its own
 * test.
 */
class OvertimeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function overtimeRow(Overtime $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'employee_id' => $like->employee_id,
            'starts' => '2027-03-01 17:00:00',
            'ends' => '2027-03-01 20:00:00',
            'purpose' => 'Inventory count',
            'mode' => 'pay',
            'reference' => 'Office Order No. 4',
            'user_id' => $like->user_id,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_overtime_needs_an_agency(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['agency_id' => null])
        ));
    }

    public function test_employee_is_required(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['employee_id' => null])
        ));
    }

    /**
     * `starts` NOT NULL, and the refusing **column** is named here because
     * the SQLSTATE alone cannot cover this rule — which mutation testing is
     * what showed. `date` is `starts::date` and is itself NOT NULL, so a null
     * `starts` produces a 23502 either way: making `starts` nullable left the
     * insert refused by `date`, and a code-only assertion passed against the
     * very change it existed to catch. The column and not the constraint name
     * because a not-null message names only the column.
     *
     * Both constraints are kept. The generated column enforcing it
     * transitively is a happy accident, not the rule — make `date` nullable
     * and it evaporates — so `starts` states it directly.
     */
    public function test_starts_is_required(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses(
            '23502',
            fn () => DB::table('overtimes')->insert($this->overtimeRow($overtime, ['starts' => null])),
            'column "starts"',
        );
    }

    /**
     * `ends` NOT NULL, unlike every `ends` elsewhere in this schema. An open
     * overtime authority is not a thing: an order names the hours it grants,
     * and a null would make overtimes_dates_ordered evaluate to NULL, which a
     * CHECK accepts.
     */
    public function test_ends_is_required(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['ends' => null])
        ));
    }

    public function test_purpose_is_required(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['purpose' => null])
        ));
    }

    public function test_mode_is_required(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['mode' => null])
        ));
    }

    public function test_approving_user_is_required(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['user_id' => null])
        ));
    }

    /** overtimes_mode_valid. */
    public function test_mode_must_be_pay_or_cto(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['mode' => 'leave'])
        ));
    }

    /**
     * overtimes_dates_ordered, and strictly greater: an authority of zero
     * length authorises nothing, and tsrange(t, t) is the empty range, which
     * overlaps nothing and would escape overtimes_no_overlap entirely.
     */
    public function test_an_authority_cannot_end_when_it_starts(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['starts' => '2027-03-01 17:00:00', 'ends' => '2027-03-01 17:00:00'])
        ));
    }

    /** Ruling P4: the primary key masks the pair, so assert the catalog. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'overtimes_id_agency_id_unique'"));
    }

    /** overtimes_employee_id_agency_id_foreign, insert side. */
    public function test_employee_must_share_the_overtimes_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Overtime::factory()->create(['employee_id' => $employee->id]));
    }

    /** Same FK, delete side. Raw DELETE: employees are soft deleted. */
    public function test_employee_with_an_authority_cannot_be_hard_deleted(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $overtime->employee_id)->delete());
    }

    /** overtimes_user_id_foreign, insert side. */
    public function test_approving_user_must_exist(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['user_id' => (string) Str::ulid()])
        ));
    }

    /** Same FK, delete side. */
    public function test_user_who_approved_an_authority_cannot_be_deleted(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('users')->where('id', $overtime->user_id)->delete());
    }

    /** The single-column user FK, for the reason suspensions.user_id is one. */
    public function test_the_approving_user_may_belong_to_another_agency(): void
    {
        $employee = Employee::factory()->create();
        $superuser = User::factory()->platform()->create();

        $overtime = Overtime::factory()->create([
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
            'user_id' => $superuser->id,
        ]);

        $this->assertSame($this->platform()->id, $overtime->user->agency_id);
    }

    /** overtimes_no_overlap: one authority at a time per employee. */
    public function test_authorities_for_one_employee_cannot_overlap(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('23P01', fn () => DB::table('overtimes')->insert($this->overtimeRow($overtime, [
            'starts' => $overtime->starts->copy()->addHour()->toDateTimeString(),
            'ends' => $overtime->ends->copy()->addHour()->toDateTimeString(),
        ])));
    }

    /**
     * **The `[)` bound, which is the one place this table's range behaves
     * differently from every daterange in the schema, and it is deliberate.**
     *
     * Two authorities meeting at an instant do not conflict: the first has
     * ended when the second begins. Two *date* ranges sharing a day do
     * conflict, because the employee really is in both on that day — same
     * operator, opposite answer, because a day is an interval and an instant
     * is not. Measured here rather than reasoned about, because "fixing" this
     * to '[]' for consistency would refuse a perfectly ordinary pair of
     * consecutive authorities.
     */
    public function test_two_authorities_may_meet_at_an_instant(): void
    {
        $first = Overtime::factory()->create(['starts' => '2026-09-15 18:00:00', 'ends' => '2026-09-15 20:00:00']);

        $second = Overtime::factory()->create([
            'agency_id' => $first->agency_id,
            'employee_id' => $first->employee_id,
            'user_id' => $first->user_id,
            'starts' => '2026-09-15 20:00:00',
            'ends' => '2026-09-15 22:00:00',
        ]);

        $this->assertDatabaseHas('overtimes', ['id' => $second->id]);
    }

    /**
     * The exclusion is keyed **per employee**, which the touching-instants
     * test above cannot show: dropping `employee_id WITH =` still permits two
     * authorities that merely meet. Two employees authorised for the *same*
     * hours is the ordinary case a global exclusion would refuse — an office
     * working a Saturday has everyone on one order.
     */
    public function test_two_employees_may_be_authorised_at_the_same_time(): void
    {
        $first = Overtime::factory()->create();
        $colleague = Employee::factory()->create(['agency_id' => $first->agency_id]);

        $second = Overtime::factory()->create([
            'agency_id' => $first->agency_id,
            'employee_id' => $colleague->id,
            'user_id' => $first->user_id,
            'starts' => $first->starts->toDateTimeString(),
            'ends' => $first->ends->toDateTimeString(),
        ]);

        $this->assertDatabaseHas('overtimes', ['id' => $second->id]);
        $this->assertNotSame($first->employee_id, $second->employee_id);
    }

    /**
     * The generated column. `date` is `starts::date`, is STORED rather than
     * Postgres 18's VIRTUAL default, and follows `starts` on an UPDATE — so
     * it cannot drift out of agreement with the instants it summarises.
     */
    public function test_the_date_is_generated_from_the_start_and_follows_it(): void
    {
        $overtime = Overtime::factory()->create(['starts' => '2026-09-15 17:00:00', 'ends' => '2026-09-15 20:00:00']);

        $this->assertSame('2026-09-15', $overtime->date->toDateString());

        DB::table('overtimes')->where('id', $overtime->id)->update([
            'starts' => '2026-10-20 17:00:00',
            'ends' => '2026-10-20 20:00:00',
        ]);

        $this->assertSame('2026-10-20', $overtime->fresh()->date->toDateString());
    }

    /**
     * An overnight authority is **one row**, which is the whole reason
     * `starts` and `ends` are timestamps, and `date` is the day it began.
     */
    public function test_an_overnight_authority_is_one_row_dated_by_its_start(): void
    {
        $overtime = Overtime::factory()->overnight()->create();

        $this->assertSame('2026-09-15', $overtime->date->toDateString());
        $this->assertSame('2026-09-16', $overtime->ends->toDateString());
        $this->assertSame(240, $overtime->minutes());
    }

    /**
     * **STORED, not VIRTUAL.** Postgres 18 defaults a generated column to
     * VIRTUAL, and a virtual column can be neither indexed nor referenced by
     * a foreign key — which is the entire reason this column exists rather
     * than being derived at read time. Blueprint's storedAs() spells it out,
     * and nothing in the migration's own text would reveal a regression to
     * virtualAs(), so the catalog is asserted directly.
     */
    public function test_the_generated_date_is_stored_and_not_virtual(): void
    {
        $column = DB::selectOne("select attgenerated from pg_attribute where attrelid = 'overtimes'::regclass and attname = 'date'");

        $this->assertSame('s', $column->attgenerated, "expected STORED ('s'), got ".var_export($column->attgenerated, true));
    }

    /** A generated column refuses a supplied value outright. */
    public function test_the_date_cannot_be_written(): void
    {
        $overtime = Overtime::factory()->create();

        $this->assertDatabaseRefuses('428C9', fn () => DB::table('overtimes')->insert(
            $this->overtimeRow($overtime, ['date' => '2027-01-01'])
        ));
    }

    /**
     * The two questions about "this date" that are not the same, and the
     * reason neither scope is named covering(). An overnight authority filed
     * on the 15th is what authorises 00:30 on the 16th: `overlapping()` finds
     * it on the 16th and `startingOn()` does not, so a deriver asking the
     * wrong one silently loses every minute after midnight.
     */
    public function test_an_overnight_authority_is_found_by_its_window_not_its_date(): void
    {
        $employee = Employee::factory()->create();
        $this->withTenant(Agency::findOrFail($employee->agency_id));
        Overtime::factory()->overnight()->create([
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
        ]);

        $sixteenth = CarbonImmutable::parse('2026-09-16');

        $this->assertSame(0, Overtime::startingOn($sixteenth)->count(), 'it is filed on the 15th');
        $this->assertSame(
            1,
            Overtime::overlapping($sixteenth->startOfDay(), $sixteenth->endOfDay())->count(),
            'but it authorises minutes on the 16th',
        );
        $this->assertSame(1, Overtime::startingOn($sixteenth->subDay())->count());
    }
}
