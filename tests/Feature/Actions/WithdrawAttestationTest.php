<?php

namespace Tests\Feature\Actions;

use App\Actions\AttestLedger;
use App\Actions\UnlockLedger;
use App\Actions\WithdrawAttestation;
use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WithdrawAttestationTest extends TestCase
{
    public function test_withdrawal_supersedes_the_rendition_and_reattestation_creates_a_new_token(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $actor = User::factory()->forAgency($agency)->permissions(Permission::ManageLedgers)->create();
        $ledger = Ledger::factory()->for($agency)->create([
            'policy' => ['template' => 'form48', 'roles' => ['employee']],
            'signers' => [['role' => 'employee', 'user_ids' => [$actor->id]]],
        ]);
        $first = app(AttestLedger::class)->handle($ledger, $actor);
        $old = $ledger->renditions()->sole();

        app(WithdrawAttestation::class)->handle($first, $actor);
        $second = app(AttestLedger::class)->handle($ledger, $actor);
        $new = $ledger->renditions()->whereNull('superseded_at')->sole();

        $this->assertNotNull($first->fresh()->withdrawn_at);
        $this->assertSame($actor->id, $first->fresh()->withdrawn_by);
        $this->assertNotNull($old->fresh()->superseded_at);
        $this->assertSame(2, $new->revision);
        $this->assertNotSame($old->token, $new->token);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_withdrawals_must_reverse_the_active_chain_before_unlock(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $actor = User::factory()->forAgency($agency)->permissions(Permission::ManageLedgers)->create();
        $ledger = Ledger::factory()->for($agency)->create([
            'policy' => ['template' => 'form48', 'roles' => ['employee', 'supervisor']],
            'signers' => [['role' => 'employee', 'user_ids' => [$actor->id]], ['role' => 'supervisor', 'user_ids' => [$actor->id]]],
        ]);
        $first = app(AttestLedger::class)->handle($ledger, $actor);
        $last = app(AttestLedger::class)->handle($ledger, $actor);

        try {
            app(WithdrawAttestation::class)->handle($first, $actor);
            $this->fail('Only the latest active attestation can be withdrawn.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attestation', $exception->errors());
        }
        $this->assertNull($first->fresh()->withdrawn_at);
        app(WithdrawAttestation::class)->handle($last, $actor);
        app(WithdrawAttestation::class)->handle($first, $actor);
        app(UnlockLedger::class)->handle($ledger, $actor);

        $this->assertFalse($ledger->fresh()->locked());
        $this->assertDatabaseCount('attestations', 2);
    }
}
