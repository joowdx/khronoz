<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Location;
use App\Support\DocumentStorage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DocumentStorageTest extends TestCase
{
    public function test_verified_copy_switches_the_primary_location_without_changing_document_identity(): void
    {
        [$document, $original, $bytes] = $this->storedDocument();

        $copy = app(DocumentStorage::class)->copy($document, 'new', 'documents/copied.pdf');

        $this->assertSame($document->id, $copy->document_id);
        $this->assertTrue($copy->primary);
        $this->assertFalse($original->fresh()->primary);
        $this->assertSame($document->digest, $document->fresh()->digest);
        Storage::disk('old-documents')->delete($original->key);
        $this->assertSame($bytes, app(DocumentStorage::class)->read($document));
    }

    public function test_an_existing_destination_must_contain_the_exact_document_bytes(): void
    {
        [$document] = $this->storedDocument();
        Storage::disk('new-documents')->put('documents/copied.pdf', '%PDF-different');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed its integrity check');

        app(DocumentStorage::class)->copy($document, 'new', 'documents/copied.pdf');
    }

    private function storedDocument(): array
    {
        Storage::fake('old-documents');
        Storage::fake('new-documents');
        config([
            'filesystems.document_stores.old.disk' => 'old-documents',
            'filesystems.document_stores.new.disk' => 'new-documents',
        ]);
        $bytes = '%PDF-1.7 immutable bytes';
        $document = Document::factory()->create([
            'bytes' => strlen($bytes),
            'algorithm' => 'sha256',
            'digest' => hash('sha256', $bytes),
        ]);
        $this->withTenant($document->agency);
        $original = Location::factory()->create([
            'agency_id' => $document->agency_id,
            'document_id' => $document->id,
            'store' => 'old',
            'key' => 'documents/original.pdf',
            'verified_at' => now(),
            'primary' => true,
        ]);
        Storage::disk('old-documents')->put($original->key, $bytes);

        return [$document, $original, $bytes];
    }
}
