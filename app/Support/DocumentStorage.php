<?php

namespace App\Support;

use App\Models\Document;
use App\Models\Location;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
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

    public function copy(Document $document, string $store, string $key, bool $primary = true): Location
    {
        $bytes = $this->read($document);
        $disk = $this->disk($store);

        if (! $disk->exists($key) && ! $disk->put($key, $bytes, ['visibility' => 'private'])) {
            throw new RuntimeException('The document copy could not be written.');
        }

        $copied = $disk->get($key);
        if (! is_string($copied)
            || strlen($copied) !== $document->bytes
            || $document->algorithm !== 'sha256'
            || ! hash_equals($document->digest, hash('sha256', $copied))) {
            throw new RuntimeException('The document copy failed its integrity check.');
        }

        return DB::transaction(function () use ($document, $store, $key, $primary): Location {
            $document = Document::query()->findOrFail($document->id);
            $document->locations()->lockForUpdate()->get();
            $location = Location::query()->where('store', $store)->where('key', $key)->first();

            if ($location !== null && $location->document_id !== $document->id) {
                throw new RuntimeException('The storage key belongs to another document.');
            }

            $location ??= Location::create([
                'agency_id' => $document->agency_id,
                'document_id' => $document->id,
                'store' => $store,
                'key' => $key,
                'primary' => false,
            ]);

            if ($primary) {
                $document->locations()->whereKeyNot($location->id)->where('primary', true)->update(['primary' => false]);
            }

            $location->update([
                'verified_at' => now(),
                'primary' => $primary,
                'retired_at' => null,
            ]);

            return $location->refresh();
        });
    }
}
