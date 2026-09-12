<?php

namespace Tests\Feature\Models;

use App\Models\Acceptance;
use App\Models\Agency;
use App\Models\User;
use App\Support\AppRoleGrants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AcceptanceTest extends TestCase
{
    public function test_duplicate_user_document_version_is_refused(): void
    {
        $row = Acceptance::factory()->create();
        $attributes = $row->getAttributes();
        $attributes['id'] = (string) Str::ulid();
        $this->assertDatabaseRefuses('23505', fn () => DB::table('acceptances')->insert($attributes));
    }

    public function test_the_user_must_belong_to_the_recorded_agency(): void
    {
        $user = User::factory()->create();
        $elsewhere = Agency::factory()->create();
        $row = Acceptance::factory()->make(['user_id' => $user->id, 'agency_id' => $elsewhere->id])->getAttributes();
        $row['id'] = (string) Str::ulid();
        $this->assertDatabaseRefuses('23503', fn () => DB::table('acceptances')->insert($row));
    }

    public function test_the_database_refuses_invalid_document_version_and_hash_values(): void
    {
        foreach (['document' => 'marketing', 'version' => '../1', 'content_hash' => 'broken'] as $field => $value) {
            $this->assertDatabaseRefuses('23514', fn () => Acceptance::factory()->create([$field => $value]));
        }
    }

    public function test_acceptance_time_is_required(): void
    {
        $this->assertDatabaseRefuses('23502', fn () => Acceptance::factory()->create(['accepted_at' => null]));
    }

    public function test_acknowledgments_cannot_be_updated_or_deleted_even_after_grants_are_refreshed(): void
    {
        $row = Acceptance::factory()->create();
        AppRoleGrants::apply();
        $this->assertDatabaseRefuses('42501', fn () => DB::table('acceptances')->where('id', $row->id)->update(['version' => 'rewritten']));
        $this->assertDatabaseRefuses('42501', fn () => DB::table('acceptances')->where('id', $row->id)->delete());
    }

    public function test_an_otherwise_permitted_account_deletion_cascades_its_acknowledgments(): void
    {
        $user = User::factory()->acceptedLegal()->create();
        $this->assertDatabaseCount('acceptances', 2);
        $user->delete();
        $this->assertDatabaseCount('acceptances', 0);
    }
}
