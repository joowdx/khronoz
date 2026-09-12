<?php

namespace App\Support;

use App\Models\Document;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DocumentStorage
{
    public function disk(string $store): FilesystemAdapter
    {
        $disk = config('filesystems.document_stores.'.$store.'.disk');
        if (! is_string($disk) || $disk === '') {
            throw new RuntimeException('The document store is not configured.');
        }

        return Storage::disk($disk);
    }

    public function read(Document $document): string
    {
        $locations = $document->locations()->whereNull('retired_at')->whereNotNull('verified_at')->orderByDesc('primary')->orderBy('id')->get();
        foreach ($locations as $location) {
            try {
                $bytes = $this->disk($location->store)->get($location->key);
                if (is_string($bytes) && strlen($bytes) === $document->bytes && $document->algorithm === 'sha256' && hash_equals($document->digest, hash('sha256', $bytes))) {
                    return $bytes;
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        throw new RuntimeException('No verified document copy is available.');
    }
}
