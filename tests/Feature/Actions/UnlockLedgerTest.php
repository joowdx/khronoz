<?php

namespace Tests\Feature\Actions;

use App\Actions\UnlockLedger;
use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Attestation;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UnlockLedgerTest extends TestCase
{
    public function test_active_attestations_prevent_unlock_without_modifying_the_lock(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $actor = User::factory()->forAgency($agency)->permissions(Permission::ManageLedgers)->create();
        $ledger = Ledger::factory()->for($agency)->create();
        Attestation::factory()->for($agency)->for($ledger)->create();

        try {
            app(UnlockLedger::class)->handle($ledger, $actor);
            $this->fail('Active attestations must block unlocking.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ledger', $exception->errors());
        }

        $this->assertTrue($ledger->fresh()->locked());
        $this->assertNull($ledger->fresh()->unlocked_by);
    }
}
