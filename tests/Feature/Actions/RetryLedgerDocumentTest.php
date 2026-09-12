<?php

namespace Tests\Feature\Actions;

use App\Actions\RetryLedgerDocument;
use App\Enums\Permission;
use App\Enums\RenditionStatus;
use App\Jobs\GenerateLedgerDocument;
use App\Models\Agency;
use App\Models\Rendition;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RetryLedgerDocumentTest extends TestCase
{
    public function test_retry_preserves_the_frozen_snapshot_and_enqueues_the_same_rendition(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $actor = User::factory()->forAgency($agency)->permissions(Permission::ManageLedgers)->create();
        $rendition = Rendition::factory()->for($agency)->create(['status' => RenditionStatus::Failed, 'requested_at' => now(), 'failed_at' => now(), 'superseded_at' => now(), 'error' => 'Generation failed']);
        $snapshot = $rendition->snapshot;
        $token = $rendition->token;
        Queue::fake([GenerateLedgerDocument::class]);

        app(RetryLedgerDocument::class)->handle($rendition, $actor);

        $rendition->refresh();
        $this->assertSame(RenditionStatus::Pending, $rendition->status);
        $this->assertNull($rendition->failed_at);
        $this->assertNull($rendition->error);
        $this->assertSame($snapshot, $rendition->snapshot);
        $this->assertSame($token, $rendition->token);
        Queue::assertPushed(GenerateLedgerDocument::class, fn ($job): bool => $job->renditionId === $rendition->id && $job->afterCommit === true);
    }
}
