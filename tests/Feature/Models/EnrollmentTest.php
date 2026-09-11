<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Terminal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One test per constraint on `enrollments` (docs/design/07-constraints.md).
 *
 * The two exclusion constraints are what make `timelogs_resolve()`
 * deterministic, so half of this file is about a rule the predecessor could
 * not express at all: its `UNIQUE (employee_id, scanner_id)` plus an `active`
 * boolean meant one enrollment per person per device *for all time*, and the
 * three positive tests here — a reissued uid, one person across two devices,
 * one uid across two devices — are each a row that schema refuses to hold.
 *
 * No `agency_not_platform` test and no such trigger: an enrollment needs an
 * employee and a terminal, and both refuse the platform row already.
 */
class EnrollmentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function enrollmentRow(Enrollment $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'employee_id' => $like->employee_id,
            'terminal_id' => $like->terminal_id,
            'uid' => (string) fake()->unique()->numberBetween(10000, 99999),
            'privilege' => 'user',
            'starts' => '2027-01-01',
            'ends' => null,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_enrollment_needs_an_agency(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, ['agency_id' => null])
        ));
    }

    /** The device user id is the whole point of the row. */
    public function test_uid_is_required(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, ['uid' => null])
        ));
    }

    public function test_privilege_is_required(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, ['privilege' => null])
        ));
    }

    /**
     * Without a lower bound the range is unbounded *below*, so an enrollment
     * would resolve every punch the device ever recorded under that uid,
     * including ones from before the person was hired.
     */
    public function test_starts_is_required(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, ['starts' => null])
        ));
    }

    /** enrollments_privilege_valid. EnumCheckContractTest holds the list itself to the enum. */
    public function test_privilege_must_be_a_known_value(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, ['privilege' => 'root'])
        ));
    }

    /** enrollments_dates_ordered. */
    public function test_an_enrollment_cannot_end_before_it_starts(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, ['starts' => '2027-03-01', 'ends' => '2027-02-28'])
        ));
    }

    /**
     * The other side of that CHECK, and why it is `>=` and not `>`: somebody
     * enrolled and withdrawn on one day is a real row, and the ranges are
     * built `'[]'`, so it covers exactly that date.
     */
    public function test_an_enrollment_may_begin_and_end_on_one_day(): void
    {
        $enrollment = Enrollment::factory()->create();
        $colleague = Employee::factory()->create(['agency_id' => $enrollment->agency_id]);

        DB::table('enrollments')->insert($this->enrollmentRow($enrollment, [
            'employee_id' => $colleague->id,
            'starts' => '2027-03-01',
            'ends' => '2027-03-01',
        ]));

        $this->assertDatabaseHas('enrollments', ['starts' => '2027-03-01', 'ends' => '2027-03-01']);
    }

    /** enrollments_employee_id_agency_id_foreign, insert side. */
    public function test_employee_must_share_the_enrollments_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Enrollment::factory()->create(['employee_id' => $employee->id]));
    }

    /**
     * Same FK, delete side. A raw DELETE, not $employee->delete(): employees
     * are soft deleted, so the Eloquent call is an UPDATE the FK never sees
     * and the test would assert nothing while passing.
     *
     * This is the predecessor's `cascadeOnDelete`, closed. There, deleting an
     * employee deleted their enrollments, which left every punch they ever
     * made unresolvable — their attendance history silently became anonymous.
     */
    public function test_employee_with_an_enrollment_cannot_be_hard_deleted(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $enrollment->employee_id)->delete());
    }

    /** enrollments_terminal_id_agency_id_foreign, insert side. */
    public function test_terminal_must_share_the_enrollments_agency(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Enrollment::factory()->create(['terminal_id' => $terminal->id]));
    }

    /** Same FK, delete side. Terminals are hard deleted, so an Eloquent call would do. */
    public function test_terminal_with_an_enrollment_cannot_be_deleted(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('terminals')->where('id', $enrollment->terminal_id)->delete());
    }

    /**
     * enrollments_uid_one_person. **This is what makes resolution
     * deterministic**: `timelogs_resolve()` is a plain SELECT with no ordering
     * and no LIMIT, which is only sound because at most one enrollment covers
     * a given (terminal, uid, date). Two overlapping rows would make "who
     * punched" depend on which row the planner happened to return first.
     */
    public function test_one_device_user_id_cannot_be_two_people_at_once(): void
    {
        $enrollment = Enrollment::factory()->create();
        $colleague = Employee::factory()->create(['agency_id' => $enrollment->agency_id]);

        // The second range **overlaps without matching**, and that is the
        // point: giving it the same `starts` would make the two ranges
        // identical, so a constraint written `WITH =` instead of `WITH &&`
        // would refuse it too and this test would pass against a rule that
        // permits every real collision. Mutation testing found exactly that.
        $this->assertDatabaseRefuses('23P01', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, [
                'employee_id' => $colleague->id,
                'uid' => $enrollment->uid,
                'starts' => $enrollment->starts->addMonths(6)->toDateString(),
            ])
        ));
    }

    /**
     * enrollments_one_uid_per_person, and **not implied by the constraint
     * above**: nothing there stops one employee being enrolled twice on one
     * terminal under two different uids on the same day. That row would split
     * their punches across two identities, and each half would read as an
     * incomplete day — an employee who clocked in as 0001 and out as 0002
     * looks like two people who each forgot one punch.
     */
    public function test_one_person_cannot_hold_two_device_user_ids_on_one_terminal_at_once(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->assertDatabaseRefuses('23P01', fn () => DB::table('enrollments')->insert(
            $this->enrollmentRow($enrollment, [
                'uid' => $enrollment->uid.'9',
                'starts' => $enrollment->starts->toDateString(),
            ])
        ));
    }

    /**
     * The case the predecessor's schema cannot hold at all: a device user id
     * reissued once its first holder's enrollment has ended.
     *
     * Devices number their users from 1, so a reset or a departure returns
     * low numbers to the pool and they get handed out again. Under
     * `UNIQUE (employee_id, scanner_id)` plus an `active` flag this could only
     * be recorded by editing the first person's row — destroying the history
     * every punch of theirs resolves through. Here both rows coexist and
     * `covering()` picks by date.
     */
    public function test_a_device_user_id_may_be_reissued_once_its_enrollment_has_ended(): void
    {
        $leaver = Enrollment::factory()->closed('+6 months')->create();
        $this->withTenant(Agency::findOrFail($leaver->agency_id));

        $terminal = Terminal::findOrFail($leaver->terminal_id);
        $joiner = Employee::factory()->create(['agency_id' => $leaver->agency_id]);

        $successor = Enrollment::factory()->on($terminal)->forEmployee($joiner)->create([
            'uid' => $leaver->uid,
            'starts' => $leaver->ends->addDay(),
        ]);

        $this->assertSame(
            [$leaver->employee_id],
            Enrollment::where('uid', $leaver->uid)->covering($leaver->starts)->pluck('employee_id')->all(),
        );
        $this->assertSame(
            [$successor->employee_id],
            Enrollment::where('uid', $leaver->uid)->covering($successor->starts)->pluck('employee_id')->all(),
        );
    }

    /**
     * Both exclusion constraints are scoped to a terminal, so one person may
     * hold a different uid on every device they use. That is the normal case —
     * each device numbers its own users — and a constraint keyed on
     * `employee_id` alone would refuse an agency with two door terminals.
     */
    public function test_one_person_may_hold_a_different_uid_on_each_terminal(): void
    {
        $enrollment = Enrollment::factory()->create();
        $this->withTenant(Agency::findOrFail($enrollment->agency_id));

        $employee = Employee::findOrFail($enrollment->employee_id);
        $annex = Terminal::factory()->create(['agency_id' => $enrollment->agency_id]);

        $second = Enrollment::factory()->on($annex)->forEmployee($employee)->create([
            'uid' => $enrollment->uid.'9',
            'starts' => $enrollment->starts,
        ]);

        $this->assertNotSame($enrollment->terminal_id, $second->terminal_id);
        $this->assertSame($enrollment->employee_id, $second->employee_id);
    }

    /**
     * And the mirror: two devices may both have a user 1, held by different
     * people, at the same time. `uid` is only meaningful alongside its
     * terminal — which is why the natural key on `timelogs` carries both.
     */
    public function test_two_terminals_may_each_have_the_same_uid_for_different_people(): void
    {
        $enrollment = Enrollment::factory()->create();
        $annex = Terminal::factory()->create(['agency_id' => $enrollment->agency_id]);
        $colleague = Employee::factory()->create(['agency_id' => $enrollment->agency_id]);

        $second = Enrollment::factory()->on($annex)->forEmployee($colleague)->create([
            'uid' => $enrollment->uid,
            'starts' => $enrollment->starts,
        ]);

        $this->assertSame($enrollment->uid, $second->uid);
        $this->assertNotSame($enrollment->employee_id, $second->employee_id);
    }

    /**
     * Ruling P4: the four-column key is masked by the primary key, so assert
     * the catalog. It is the target of `timelogs`' paired FK, which is what
     * makes that FK a second lock on resolution rather than a formality.
     */
    public function test_the_resolution_key_is_declared_unique(): void
    {
        $this->assertSame(
            'UNIQUE (id, employee_id, terminal_id, uid)',
            DB::selectOne("select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'enrollments_resolution_key'")->def,
        );
    }

    /** Ruling P4 again, for the tenancy pair. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'enrollments_id_agency_id_unique'"));
    }
}
