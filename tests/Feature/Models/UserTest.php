<?php

namespace Tests\Feature\Models;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserTest extends TestCase
{
    public function test_permissions_must_be_distinct_strings(): void
    {
        $agency = Agency::factory()->create();
        $row = fn (string $permissions) => ['id' => (string) Str::ulid(), 'agency_id' => $agency->id, 'name' => 'x', 'email' => Str::random().'@x.test', 'password' => 'x', 'permissions' => $permissions, 'created_at' => now(), 'updated_at' => now()];

        $this->assertDatabaseRefuses('23514', fn () => DB::table('users')->insert($row('{"a":1}')));
        $this->assertDatabaseRefuses('23514', fn () => DB::table('users')->insert($row('[1]')));
        $this->assertDatabaseRefuses('23514', fn () => DB::table('users')->insert($row('["users.manage","users.manage"]')));
    }

    public function test_email_is_unique_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'Ana@Agency.gov.ph']);

        $this->assertDatabaseRefuses('23505', fn () => User::factory()->create(['email' => 'ana@agency.gov.ph']));
    }

    public function test_email_unique_index_is_case_insensitive(): void
    {
        $user = User::factory()->create(['email' => 'ana@agency.gov.ph']);

        // Inserted through the query builder, bypassing User's email mutator, so this
        // duplicate reaches Postgres in a different case than the stored row rather than
        // the identical lower-cased literal a model-level create() would produce. Only a
        // functional index on lower(email) can refuse a raw, differently-cased duplicate
        // like this; a plain unique index on the column would let it through. This is what
        // distinguishes the two, so don't "simplify" this back to a factory call.
        $this->assertDatabaseRefuses('23505', fn () => DB::table('users')->insert([
            'id' => (string) Str::ulid(),
            'agency_id' => $user->agency_id,
            'name' => 'x',
            'email' => 'ANA@agency.gov.ph',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_user_needs_an_agency(): void
    {
        $this->assertDatabaseRefuses('23502', fn () => User::factory()->create(['agency_id' => null]));
    }

    /**
     * UNIQUE (id, agency_id) is the pair Milestone 2's paired foreign keys
     * will reference (docs/design/07-constraints.md, "carry the parent's
     * key"). A duplicate id alone already trips the primary key, but this
     * proves the compound index the FK needs is actually there too, not
     * just assumed from the primary key — if this migration line silently
     * vanished, M2's FK creation would fail with an error pointing nowhere
     * near the cause.
     */
    public function test_id_and_agency_id_pair_is_unique(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('users')->insert([
            'id' => $user->id,
            'agency_id' => $user->agency_id,
            'name' => 'x',
            'email' => Str::random().'@x.test',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /** users.agency_id references agencies(id); a nonexistent agency is refused, not silently accepted. */
    public function test_agency_id_must_reference_an_existing_agency(): void
    {
        $this->assertDatabaseRefuses('23503', fn () => DB::table('users')->insert([
            'id' => (string) Str::ulid(),
            'agency_id' => (string) Str::ulid(), // no such agency
            'name' => 'x',
            'email' => Str::random().'@x.test',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * users.employee_id is unique, so Milestone 2's employees pairing can
     * never resolve to two colleagues at once. Both users must share the
     * employee's own agency_id — the paired FK (employee_id, agency_id)
     * added in Task 2 refuses a mismatch before this UNIQUE is ever reached.
     */
    public function test_employee_id_is_unique(): void
    {
        $employee = Employee::factory()->create();
        User::factory()->create(['agency_id' => $employee->agency_id, 'employee_id' => $employee->id]);

        $this->assertDatabaseRefuses('23505', fn () => User::factory()->create([
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
        ]));
    }

    /**
     * users_employee_id_agency_id_foreign, insert side. Two ways to fail it:
     * an employee of a different agency, and a platform user (superuser)
     * given any employee_id at all — the platform agency has no employees
     * (agency_not_platform on employees), so no (id, agency_id) pair for it
     * ever exists. Makes docs/design/02-access.md rule 3's claim empirical.
     */
    public function test_employee_id_must_share_the_users_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => User::factory()->create(['employee_id' => $employee->id]));

        $another = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => User::factory()->platform()->create(['employee_id' => $another->id]));
    }

    /**
     * users_employee_id_agency_id_foreign, delete side. A raw DELETE, not
     * $employee->delete() — employees are soft deleted, so the Eloquent call
     * is an UPDATE the FK never sees.
     */
    public function test_employee_linked_to_a_user_cannot_be_hard_deleted(): void
    {
        $employee = Employee::factory()->create();
        User::factory()->create(['agency_id' => $employee->agency_id, 'employee_id' => $employee->id]);

        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $employee->id)->delete());
    }

    /**
     * A staff user with no linked employee still inserts, now that the
     * paired FK exists: MATCH SIMPLE skips the check when employee_id itself
     * is null. docs/design/07-constraints.md explicitly allows this path —
     * "the violation, or the insert" — and this is what keeps it open.
     */
    public function test_a_staff_user_can_have_no_linked_employee(): void
    {
        $user = User::factory()->create(['employee_id' => null]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'employee_id' => null]);
    }

    /** permissions is NOT NULL (with a database default of '[]'); an explicit null must still be refused. */
    public function test_permissions_cannot_be_null(): void
    {
        $agency = Agency::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('users')->insert([
            'id' => (string) Str::ulid(),
            'agency_id' => $agency->id,
            'name' => 'x',
            'email' => Str::random().'@x.test',
            'password' => 'x',
            'permissions' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_allows_follows_held_and_implied_permissions(): void
    {
        $user = User::factory()->permissions(Permission::ManageScheduling)->create();

        $this->assertTrue($user->allows(Permission::ManageScheduling));
        $this->assertTrue($user->allows(Permission::ViewScheduling));
        $this->assertFalse($user->allows(Permission::ManageUsers));
    }

    public function test_platform_user_passes_every_gate(): void
    {
        $superuser = User::factory()->platform()->create();
        $this->assertTrue($superuser->isPlatform());
        $this->assertTrue(Gate::forUser($superuser)->allows(Permission::ManageUsers->value));

        $staff = User::factory()->create();
        $this->assertFalse($staff->isPlatform());
        $this->assertFalse(Gate::forUser($staff)->allows(Permission::ManageUsers->value));
    }
}
