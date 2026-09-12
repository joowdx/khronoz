<?php

namespace Tests\Feature\Actions;

use App\Actions\AttestLedger;
use App\Enums\RenditionStatus;
use App\Jobs\GenerateLedgerDocument;
use App\Models\Agency;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttestLedgerTest extends TestCase
{
    public function test_final_attestation_always_creates_a_frozen_verifiable_rendition_without_archiving(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $actor = User::factory()->forAgency($agency)->create(['name' => 'Ana Signer']);
        $ledger = Ledger::factory()->for($agency)->create([
            'policy' => ['template' => 'form48', 'roles' => ['employee']],
            'signers' => [['role' => 'employee', 'user_ids' => [$actor->id]]],
            'identity' => ['employee' => ['name' => 'Frozen Employee']],
            'calculation' => ['totals' => ['worked' => 480], 'workdays' => []],
        ]);
        Queue::fake();

        $attestation = app(AttestLedger::class)->handle($ledger, $actor);
        $actor->update(['name' => 'Renamed User']);
        $rendition = $ledger->renditions()->sole();

        $this->assertSame('Ana Signer', $attestation->fresh()->name);
        $this->assertSame(1, $attestation->sequence);
        $this->assertSame(RenditionStatus::Unstored, $rendition->status);
        $this->assertSame('Frozen Employee', $rendition->snapshot['employee']['name']);
        $this->assertSame('Ana Signer', $rendition->snapshot['attestations'][0]['name']);
        $this->assertSame(480, $rendition->snapshot['totals']['worked']);
        $this->assertSame(64, strlen($rendition->token));
        $this->assertNull($rendition->document_id);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_archiving_opt_in_dispatches_the_pdf_job_after_commit(): void
    {
        $agency = Agency::factory()->create(['settings' => ['ledger_archiving' => true]]);
        $this->withTenant($agency);
        $actor = User::factory()->forAgency($agency)->create();
        $ledger = Ledger::factory()->for($agency)->create([
            'policy' => ['template' => 'plain', 'roles' => ['timekeeper']],
            'signers' => [['role' => 'timekeeper', 'user_ids' => [$actor->id]]],
        ]);
        Queue::fake([GenerateLedgerDocument::class]);

        app(AttestLedger::class)->handle($ledger, $actor);

        $rendition = $ledger->renditions()->sole();
        $this->assertSame(RenditionStatus::Pending, $rendition->status);
        Queue::assertPushed(GenerateLedgerDocument::class, fn ($job): bool => $job->renditionId === $rendition->id && $job->agencyId === $agency->id && $job->queue === 'pdfs' && $job->afterCommit === true);
    }

    public function test_rejects_skipping_the_next_role_without_writing_an_attestation(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $actor = User::factory()->forAgency($agency)->create();
        $ledger = Ledger::factory()->for($agency)->create([
            'policy' => ['template' => 'form48', 'roles' => ['employee', 'supervisor']],
            'signers' => [['role' => 'employee', 'user_ids' => [$actor->id]], ['role' => 'supervisor', 'user_ids' => [$actor->id]]],
        ]);

        try {
            app(AttestLedger::class)->handle($ledger, $actor, 'supervisor');
            $this->fail('A role cannot be skipped.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }

        $this->assertDatabaseCount('attestations', 0);
        $this->assertDatabaseCount('renditions', 0);
    }
}
