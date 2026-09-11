<?php

namespace Tests\Feature\Models;

use App\Models\Attestation;
use App\Models\Ledger;
use App\Models\User;
use App\Support\AppRoleGrants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One test per constraint and trigger on `attestations`
 * (docs/design/07-constraints.md).
 *
 * No agency_not_platform test: an attestation needs a ledger, and a ledger
 * needs an employee, and `employees` refuses the platform agency already.
 *
 * attestations_agency_id_foreign is untested on both sides for the reason
 * Ruling P5 gives on deployments: both paired FKs include agency_id, so no
 * row exists where this FK alone fails. Its delete side is covered
 * transitively by the employees / ledgers agency-delete tests.
 *
 * The row helper uses `supervisor` so it does not collide with the factory
 * default (`timekeeper`) on UNIQUE (ledger_id, role).
 */
class AttestationTest extends TestCase
{
    /** @return array<string, mixed> */
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

    /** Ruling P4: the primary key masks the pair, so assert the catalog. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'attestations_id_agency_id_unique'"));
    }

    /** attestations_ledger_id_role_unique. */
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

    /** attestations_ledger_id_agency_id_foreign, insert side. */
    public function test_ledger_must_share_the_attestations_agency(): void
    {
        $attestation = Attestation::factory()->create();
        $other = Ledger::factory()->locked()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('attestations')->insert(
            $this->attestationRow($attestation, ['ledger_id' => $other->id])
        ));
    }

    /** Same FK, delete side. */
    public function test_ledger_with_an_attestation_cannot_be_deleted(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('ledgers')->where('id', $attestation->ledger_id)->delete());
    }

    /**
     * attestations_user_id_agency_id_foreign, insert side. A signature must
     * come from inside the agency — the opposite of suspensions / exemptions /
     * overtimes, which leave user_id unpaired so a platform superuser who
     * entered the agency can do the data entry. The pair is the constraint,
     * so a stranger of a third agency is 23503, not actor_of_agency's P0001.
     *
     * The stranger is built before any tenant is set, for the reason
     * BelongsToAgency fills agency_id from the tenant on creating.
     */
    public function test_the_signer_must_belong_to_the_ledgers_agency(): void
    {
        $attestation = Attestation::factory()->create();
        $stranger = User::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('attestations')->insert(
            $this->attestationRow($attestation, ['user_id' => $stranger->id])
        ));
    }

    /**
     * The same pair refuses a platform superuser. That is why there is no
     * actor_of_agency trigger here: the FK already says "this agency", and
     * a signature is not data entry.
     */
    public function test_a_platform_superuser_cannot_sign(): void
    {
        $attestation = Attestation::factory()->create();
        $superuser = User::factory()->platform()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('attestations')->insert(
            $this->attestationRow($attestation, ['user_id' => $superuser->id])
        ));
    }

    /** Same FK, delete side. */
    public function test_user_who_signed_cannot_be_deleted(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('users')->where('id', $attestation->user_id)->delete());
    }

    /**
     * attestations_role_valid. Shape only — which strings are legal is the
     * agency's settings.attestations list, checked by the application.
     */
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

    /**
     * attestations_locked, refusing path. Locking a ledger with no workdays
     * is permitted — LedgerTest already proves it — so the unlocked parent
     * here is a factory default, not a special state.
     */
    public function test_an_unlocked_ledger_cannot_be_attested(): void
    {
        $ledger = Ledger::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => Attestation::factory()->create([
            'agency_id' => $ledger->agency_id,
            'ledger_id' => $ledger->id,
        ]));
    }

    /** attestations_locked, permitting path. */
    public function test_a_locked_ledger_may_be_attested(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseHas('attestations', ['id' => $attestation->id]);
    }

    /**
     * A signature is added or removed, never edited. Raw UPDATE through the
     * app connection, which is the default — the form the other privilege
     * tests use.
     */
    public function test_the_app_role_cannot_update_a_signature(): void
    {
        $attestation = Attestation::factory()->create();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('attestations')->where('id', $attestation->id)->update([
            'role' => 'head',
        ]));
    }

    /** INSERT and DELETE stay: you un-certify by removing the row. */
    public function test_the_app_role_may_delete_a_signature(): void
    {
        $attestation = Attestation::factory()->create();

        DB::table('attestations')->where('id', $attestation->id)->delete();

        $this->assertDatabaseMissing('attestations', ['id' => $attestation->id]);
    }

    /**
     * The privileges are invisible to every constraint catalog, so they get a
     * read-back of their own: table-level INSERT, SELECT and DELETE, no UPDATE.
     */
    public function test_the_granted_privileges_are_insert_select_and_delete(): void
    {
        $table = DB::table('information_schema.table_privileges')
            ->where('grantee', 'chronoz')->where('table_name', 'attestations')
            ->orderBy('privilege_type')->pluck('privilege_type')->all();

        $this->assertSame(['DELETE', 'INSERT', 'SELECT'], $table);
    }

    /**
     * Decision 41: `db:grant` re-runs apply(), which grants CRUD on every
     * table, so a REVOKE written only in the migration would be silently
     * undone. restrict() is called from inside apply(), and this asserts the
     * loop closes for this table.
     */
    public function test_re_running_the_deploy_grants_does_not_restore_update(): void
    {
        $attestation = Attestation::factory()->create();

        AppRoleGrants::apply();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('attestations')->where('id', $attestation->id)->update([
            'at' => now(),
        ]));
    }
}
