<?php

namespace Tests\Feature\Models;

use App\Enums\Permission;
use App\Models\Agency;
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

    /** users.employee_id is unique, so Milestone 2's employees pairing can never resolve to two colleagues at once. */
    public function test_employee_id_is_unique(): void
    {
        $employeeId = (string) Str::ulid();
        User::factory()->create(['employee_id' => $employeeId]);

        $this->assertDatabaseRefuses('23505', fn () => User::factory()->create(['employee_id' => $employeeId]));
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
