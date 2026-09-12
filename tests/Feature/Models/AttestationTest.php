<?php

namespace Tests\Feature\Models;

use App\Models\Attestation;
use App\Models\Ledger;
use App\Models\User;
use App\Support\AppRoleGrants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttestationTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function attestationRow(Attestation $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'ledger_id' => $like->ledger_id,
            'role' => 'supervisor',
            'user_id' => $like->user_id,
            'at' => '2026-09-11 12:00:00',
            ...$overrides,
        ];
    }

    public function test_attestation_needs_an_agency(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('attestations')->insert(
            $this->attestationRow($attestation, ['agency_id' => null])
        ));
    }

    public function test_ledger_is_required(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('attestations')->insert(
            $this->attestationRow($attestation, ['ledger_id' => null])
        ));
    }

    public function test_role_is_required(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('attestations')->insert(
            $this->attestationRow($attestation, ['role' => null])
        ));
    }

    public function test_user_is_required(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('attestations')->insert(
            $this->attestationRow($attestation, ['user_id' => null])
        ));
    }

    public function test_at_is_required(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('attestations')->insert(
            $this->attestationRow($attestation, ['at' => null])
        ));
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'attestations_id_agency_id_unique'"));
    }

    public function test_one_role_cannot_sign_the_same_ledger_twice(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseRefuses(
            '23505',
            fn () => DB::table('attestations')->insert($this->attestationRow($attestation, [
                'role' => $attestation->role,
            ])),
            'attestations_ledger_id_role_unique',
        );
    }

    public function test_two_roles_may_sign_the_same_ledger(): void
    {
        $first = Attestation::factory()->create();

        $second = Attestation::factory()->create([
            'agency_id' => $first->agency_id,
            'ledger_id' => $first->ledger_id,
            'user_id' => $first->user_id,
            'role' => 'supervisor',
        ]);

        $this->assertDatabaseHas('attestations', ['id' => $second->id]);
    }

    public function test_the_same_role_may_sign_two_ledgers(): void
    {
        $first = Attestation::factory()->create();

        $second = Attestation::factory()->create([
            'agency_id' => $first->agency_id,
            'user_id' => $first->user_id,
            'role' => $first->role,
        ]);

        $this->assertDatabaseHas('attestations', ['id' => $second->id]);
        $this->assertNotSame($first->ledger_id, $second->ledger_id);
    }

    public function test_ledger_must_share_the_attestations_agency(): void
    {
        $attestation = Attestation::factory()->create();
        $other = Ledger::factory()->locked()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('attestations')->insert(
            $this->attestationRow($attestation, ['ledger_id' => $other->id])
        ));
    }

    public function test_ledger_with_an_attestation_cannot_be_deleted(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('ledgers')->where('id', $attestation->ledger_id)->delete());
    }

    public function test_the_signer_must_belong_to_the_ledgers_agency(): void
    {
        $attestation = Attestation::factory()->create();
        $stranger = User::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('attestations')->insert(
            $this->attestationRow($attestation, ['user_id' => $stranger->id])
        ));
    }

    public function test_a_platform_superuser_cannot_sign(): void
    {
        $attestation = Attestation::factory()->create();
        $superuser = User::factory()->platform()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('attestations')->insert(
            $this->attestationRow($attestation, ['user_id' => $superuser->id])
        ));
    }

    public function test_user_who_signed_cannot_be_deleted(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('users')->where('id', $attestation->user_id)->delete());
    }

    public function test_role_must_be_a_short_lowercase_snake(): void
    {
        $attestation = Attestation::factory()->create();

        foreach (['Timekeeper', 'time-keeper', 'head1', '', str_repeat('a', 33)] as $role) {
            $this->assertDatabaseRefuses(
                '23514',
                fn () => DB::table('attestations')->insert($this->attestationRow($attestation, ['role' => $role])),
                'attestations_role_valid',
            );
        }

        $id = (string) Str::ulid();
        DB::table('attestations')->insert($this->attestationRow($attestation, [
            'id' => $id,
            'role' => str_repeat('a', 32),
        ]));
        $this->assertDatabaseHas('attestations', ['id' => $id]);
    }

    public function test_an_unlocked_ledger_cannot_be_attested(): void
    {
        $ledger = Ledger::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => Attestation::factory()->create([
            'agency_id' => $ledger->agency_id,
            'ledger_id' => $ledger->id,
        ]));
    }

    public function test_a_locked_ledger_may_be_attested(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseHas('attestations', ['id' => $attestation->id]);
    }

    public function test_the_app_role_cannot_update_a_signature(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('attestations')->where('id', $attestation->id)->update([
            'role' => 'head',
        ]));
    }

    public function test_the_app_role_may_delete_a_signature(): void
    {
        $attestation = Attestation::factory()->create();

        DB::table('attestations')->where('id', $attestation->id)->delete();

        $this->assertDatabaseMissing('attestations', ['id' => $attestation->id]);
    }

    public function test_the_granted_privileges_are_insert_select_and_delete(): void
    {
        $table = DB::table('information_schema.table_privileges')
            ->where('grantee', 'chronoz')->where('table_name', 'attestations')
            ->orderBy('privilege_type')->pluck('privilege_type')->all();

        $this->assertSame(['DELETE', 'INSERT', 'SELECT'], $table);
    }

    public function test_re_running_the_deploy_grants_does_not_restore_update(): void
    {
        $attestation = Attestation::factory()->create();

        AppRoleGrants::apply();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('attestations')->where('id', $attestation->id)->update([
            'at' => now(),
        ]));
    }
}
