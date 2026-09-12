<?php

namespace App\Support;

use Illuminate\Support\Str;
use RuntimeException;

final class Legal
{
    public function __construct(private ?string $directory = null) {}

    public const array DOCUMENTS = ['privacy-policy', 'user-agreement'];

    /** @return array<string, array<string, mixed>> */
    public function catalog(): array
    {
        return json_decode(file_get_contents(($this->directory ?? resource_path('legal')).'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function document(string $slug, ?string $version = null): array
    {
        abort_unless(in_array($slug, self::DOCUMENTS, true), 404);
        $catalog = $this->catalog();
        $entry = $catalog[$slug];
        $version ??= $entry['current'];
        abort_unless(isset($entry['versions'][$version]), 404);

        if (! preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $version)) {
            throw new RuntimeException('Invalid legal version identifier.');
        }

        $metadata = $entry['versions'][$version];
        $markdown = file_get_contents(($this->directory ?? resource_path('legal'))."/{$slug}/{$version}.md");

        if (! hash_equals($metadata['sha256'], hash('sha256', $markdown))) {
            throw new RuntimeException("Legal document integrity check failed: {$slug}/{$version}.");
        }

        if (! in_array($metadata['status'], ['draft', 'published'], true)) {
            throw new RuntimeException('Invalid legal publication status.');
        }

        if ($metadata['status'] === 'published' && (
            str_starts_with($version, 'draft-') || str_contains($markdown, '[[') ||
            ! is_string($metadata['effective_at']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $metadata['effective_at'])
        )) {
            throw new RuntimeException('Published documents need a new version, completed details and an effective date.');
        }

        return [
            'slug' => $slug,
            'title' => $entry['title'],
            'version' => $version,
            'status' => $metadata['status'],
            'effective_at' => $metadata['effective_at'],
            'hash' => $metadata['sha256'],
            'html' => Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            'url' => route('legal.show', ['document' => $slug, 'version' => $version]),
            'versions' => array_keys($entry['versions']),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function current(): array
    {
        return array_map(fn (string $slug): array => $this->document($slug), self::DOCUMENTS);
    }

    public function isPublished(): bool
    {
        return collect($this->current())->every(fn (array $document): bool => $document['status'] === 'published');
    }
}
