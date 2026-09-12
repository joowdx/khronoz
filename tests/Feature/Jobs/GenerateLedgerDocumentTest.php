<?php

namespace Tests\Feature\Jobs;

use App\Enums\RenditionStatus;
use App\Jobs\GenerateLedgerDocument;
use App\Models\Agency;
use App\Models\Rendition;
use App\Support\DocumentStorage;
use App\Support\LedgerPdf;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

class GenerateLedgerDocumentTest extends TestCase
{
    public function test_job_sets_its_tenant_verifies_archived_bytes_and_is_idempotent(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $rendition = Rendition::factory()->for($agency)->create(['status' => RenditionStatus::Pending, 'requested_at' => now(), 'template' => 'plain']);
        config(['filesystems.document_stores.archive.disk' => 'ledger-test']);
        Storage::fake('ledger-test');
        Pdf::fake();
        Route::get('/test/verify/{token}', fn () => 'verified')->name('ledgers.verify');
        Route::getRoutes()->refreshNameLookups();
        $tenant = app(Tenant::class);
        $tenant->forget();
        $job = new GenerateLedgerDocument($agency->id, $rendition->id);

        $job->handle($tenant, app(LedgerPdf::class), app(DocumentStorage::class));
        $job->handle($tenant, app(LedgerPdf::class), app(DocumentStorage::class));

        $this->assertFalse($tenant->check());
        $this->withTenant($agency);
        $rendition->refresh();
        $this->assertSame(RenditionStatus::Ready, $rendition->status);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('locations', 1);
        $bytes = app(DocumentStorage::class)->read($rendition->document);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame(hash('sha256', $bytes), $rendition->document->digest);
        $this->assertSame(strlen($bytes), $rendition->document->bytes);
        Pdf::assertViewHas('preview', false);
    }

    public function test_retry_reuses_an_existing_object_instead_of_replacing_its_bytes(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $rendition = Rendition::factory()->for($agency)->create(['status' => RenditionStatus::Pending, 'requested_at' => now()]);
        config(['filesystems.document_stores.archive.disk' => 'ledger-test']);
        Storage::fake('ledger-test');
        $bytes = "%PDF-1.7\noriginal immutable bytes";
        Storage::disk('ledger-test')->put('documents/'.$agency->id.'/'.$rendition->id.'.pdf', $bytes);
        $pdf = $this->mock(LedgerPdf::class);
        $pdf->shouldNotReceive('render');

        (new GenerateLedgerDocument($agency->id, $rendition->id))->handle(app(Tenant::class), $pdf, app(DocumentStorage::class));

        $this->assertSame($bytes, app(DocumentStorage::class)->read($rendition->fresh()->document));
        $this->assertTrue(app(Tenant::class)->check());
    }

    public function test_corrupt_existing_object_is_not_registered_as_a_document(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $rendition = Rendition::factory()->for($agency)->create(['status' => RenditionStatus::Pending, 'requested_at' => now()]);
        config(['filesystems.document_stores.archive.disk' => 'ledger-test']);
        Storage::fake('ledger-test');
        Storage::disk('ledger-test')->put('documents/'.$agency->id.'/'.$rendition->id.'.pdf', 'broken');

        try {
            (new GenerateLedgerDocument($agency->id, $rendition->id))->handle(app(Tenant::class), app(LedgerPdf::class), app(DocumentStorage::class));
            $this->fail('Corrupt PDF must not be registered.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The archived object is not a PDF.', $exception->getMessage());
        }

        $this->assertSame(RenditionStatus::Pending, $rendition->fresh()->status);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('locations', 0);
    }

    public function test_terminal_failure_marks_only_pending_archives_failed(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $rendition = Rendition::factory()->for($agency)->create(['status' => RenditionStatus::Pending, 'requested_at' => now()]);
        app(Tenant::class)->forget();

        (new GenerateLedgerDocument($agency->id, $rendition->id))->failed(new RuntimeException('private endpoint details'));

        $this->assertFalse(app(Tenant::class)->check());
        $this->withTenant($agency);
        $this->assertSame(RenditionStatus::Failed, $rendition->fresh()->status);
        $this->assertNotNull($rendition->fresh()->failed_at);
        $this->assertStringNotContainsString('private endpoint', $rendition->fresh()->error);
    }
}
