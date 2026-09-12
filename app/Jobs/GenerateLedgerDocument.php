<?php

namespace App\Jobs;

use App\Enums\RenditionStatus;
use App\Models\Agency;
use App\Models\Document;
use App\Models\Location;
use App\Models\Rendition;
use App\Support\DocumentStorage;
use App\Support\LedgerPdf;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class GenerateLedgerDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public array $backoff = [10, 30, 60, 120];

    public function __construct(public string $agencyId, public string $renditionId)
    {
        $this->onQueue('pdfs');
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->renditionId))->releaseAfter(15)->expireAfter(90)];
    }

    public function handle(Tenant $tenant, LedgerPdf $pdf, DocumentStorage $storage): void
    {
        $tenant->within(Agency::findOrFail($this->agencyId), function () use ($pdf, $storage): void {
            Cache::lock('ledger-document:'.$this->renditionId, 90)->block(5, function () use ($pdf, $storage): void {
                $rendition = Rendition::query()->findOrFail($this->renditionId);
                if ($rendition->status !== RenditionStatus::Pending) {
                    return;
                }
                $store = config('filesystems.document_store', 'archive');
                $disk = $storage->disk($store);
                $key = 'documents/'.$this->agencyId.'/'.$rendition->id.'.pdf';
                $generatedAt = $rendition->requested_at ?? now()->toImmutable();
                if ($disk->exists($key)) {
                    $bytes = $disk->get($key);
                } else {
                    $snapshot = $rendition->snapshot;
                    $snapshot['document'] = ['generated_at' => $generatedAt->toIso8601String()];
                    $bytes = $pdf->render($snapshot, $rendition->template, $rendition->token);
                    if (! str_starts_with($bytes, '%PDF-') || ! $disk->put($key, $bytes, ['visibility' => 'private'])) {
                        throw new RuntimeException('The canonical PDF could not be written.');
                    }
                }
                if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF-')) {
                    throw new RuntimeException('The archived object is not a PDF.');
                }
                $digest = hash('sha256', $bytes);
                $verified = $disk->get($key);
                if (! is_string($verified) || strlen($verified) !== strlen($bytes) || ! hash_equals($digest, hash('sha256', $verified))) {
                    throw new RuntimeException('The stored PDF failed its integrity check.');
                }
                DB::transaction(function () use ($bytes, $digest, $generatedAt, $store, $key): void {
                    $rendition = Rendition::query()->lockForUpdate()->findOrFail($this->renditionId);
                    if ($rendition->status !== RenditionStatus::Pending) {
                        return;
                    }
                    $document = Document::create(['agency_id' => $this->agencyId, 'name' => 'attendance.pdf', 'mime' => 'application/pdf', 'bytes' => strlen($bytes), 'algorithm' => 'sha256', 'digest' => $digest]);
                    Location::create(['agency_id' => $this->agencyId, 'document_id' => $document->id, 'store' => $store, 'key' => $key, 'verified_at' => now(), 'primary' => true]);
                    $rendition->update(['status' => RenditionStatus::Ready, 'document_id' => $document->id, 'generated_at' => $generatedAt, 'failed_at' => null, 'error' => null]);
                });
            });
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(Tenant::class)->within(Agency::findOrFail($this->agencyId), function (): void {
            Rendition::query()->whereKey($this->renditionId)->whereIn('status', [RenditionStatus::Pending, RenditionStatus::Failed])->update([
                'status' => RenditionStatus::Failed,
                'failed_at' => now(),
                'error' => 'The PDF archive could not be generated. Please retry.',
            ]);
        });
    }
}
