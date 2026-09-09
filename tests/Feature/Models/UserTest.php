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

    public function test_user_needs_an_agency(): void
    {
        $this->assertDatabaseRefuses('23502', fn () => User::factory()->create(['agency_id' => null]));
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
