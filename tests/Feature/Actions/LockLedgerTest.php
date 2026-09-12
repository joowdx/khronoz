<?php

namespace Tests\Feature\Actions;

use App\Actions\LockLedger;
use App\Actions\UnlockLedger;
use App\Enums\Permission;
use App\Enums\Work;
use App\Models\Agency;
use App\Models\Cadence;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LockLedgerTest extends TestCase
{
    public function test_lock_freezes_identity_and_unlock_preserves_revision_history(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $cadence = Cadence::factory()->for($agency)->create();
        $employee = Employee::factory()->for($agency)->for($cadence)->create(['first_name' => 'Ana']);
        $actor = User::factory()->forAgency($agency)->permissions(Permission::ManageLedgers)->create();
        User::factory()->forAgency($agency)->create(['employee_id' => $employee->id]);
        Policy::factory()->for($agency)->create(['roles' => ['employee']]);

        $first = app(LockLedger::class)->handle($employee, '2026-08-01', '2026-08-31', Work::All, $actor);
        app(UnlockLedger::class)->handle($first, $actor);
        $employee->update(['first_name' => 'Maria']);
        $second = app(LockLedger::class)->handle($employee, '2026-08-01', '2026-08-31', Work::All, $actor);

        $this->assertSame(2, $second->revision);
        $this->assertNotSame($first->id, $second->id);
        $this->assertStringStartsWith('Ana ', $first->fresh()->identity['employee']['name']);
        $this->assertStringStartsWith('Maria ', $second->identity['employee']['name']);
        $this->assertNotNull($first->fresh()->locked_at);
        $this->assertNotNull($first->fresh()->unlocked_at);
    }

    public function test_employee_cannot_lock_a_range_outside_the_assigned_cadence(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $cadence = Cadence::factory()->for($agency)->create();
        $employee = Employee::factory()->for($agency)->for($cadence)->create();
        $actor = User::factory()->forAgency($agency)->permissions(Permission::ManageLedgers)->create(['employee_id' => $employee->id]);

        try {
            app(LockLedger::class)->handle($employee, '2026-08-01', '2026-08-15', Work::All, $actor);
            $this->fail('A partial monthly range must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts', $exception->errors());
        }

        $this->assertDatabaseCount('ledgers', 0);
    }

    public function test_factory_snapshot_keys_match_the_locked_snapshot_contract(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->for($agency)->create();
        $actor = User::factory()->forAgency($agency)->permissions(Permission::ManageLedgers)->create(['employee_id' => $employee->id]);
        Policy::factory()->for($agency)->create(['roles' => ['employee']]);

        $ledger = app(LockLedger::class)->handle($employee, '2026-08-01', '2026-08-31', Work::All, $actor);
        $factory = Ledger::factory()->for($agency)->make();

        $this->assertSame(array_keys($ledger->identity), array_keys($factory->identity));
        $this->assertSame(array_keys($ledger->calculation), array_keys($factory->calculation));
        $this->assertSame(array_keys($ledger->calculation['totals']), array_keys($factory->calculation['totals']));
        $this->assertSame(array_keys($ledger->calculation['settings']), array_keys($factory->calculation['settings']));
        $this->assertSame(array_keys($ledger->signers[0]), array_keys($factory->signers[0]));
    }

    public function test_required_unresolved_signer_prevents_locking(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->for($agency)->create();
        $actor = User::factory()->forAgency($agency)->permissions(Permission::ManageLedgers)->create();

        try {
            app(LockLedger::class)->handle($employee, '2026-08-01', '2026-08-31', Work::All, $actor);
            $this->fail('An unresolved required signer must prevent locking.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('policy', $exception->errors());
        }

        $this->assertDatabaseCount('ledgers', 0);
    }
}
