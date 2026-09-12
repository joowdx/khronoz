<?php

namespace Tests\Feature\Http\Controllers;

use App\Support\Legal;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class LegalControllerTest extends TestCase
{
    private ?string $fixture = null;

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            File::deleteDirectory($this->fixture);
        }

        parent::tearDown();
    }

    public function test_current_documents_are_public_drafts_with_accessible_version_urls(): void
    {
        foreach (app(Legal::class)->current() as $document) {
            $this->get(route('legal.show', ['document' => $document['slug']]))
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('legal/show')->where('document.status', 'draft')
                ->where('document.version', $document['version'])
                ->where('document.hash', $document['hash']));

            $this->get($document['url'])->assertOk();
        }

        $this->assertTrue(Route::getRoutes()->getByName('legal.show')->getMetadata('ssr'));
    }

    public function test_unknown_documents_and_versions_are_not_found(): void
    {
        $this->get('/privacy-policy/not-a-version')->assertNotFound();
        $this->get('/unknown-agreement')->assertNotFound();
    }

    public function test_all_retained_versions_pass_integrity_but_drafts_fail_publication_readiness(): void
    {
        $this->artisan('legal:check')->assertSuccessful();
        $this->artisan('legal:check', ['--published' => true])->assertFailed();
    }

    public function test_markdown_strips_raw_html_and_unsafe_links(): void
    {
        $this->documents('<script>alert(1)</script> <img src=x onerror=alert(1)> [unsafe](javascript:alert%281%29)');
        $html = app(Legal::class)->document('privacy-policy')['html'];

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_altered_document_bytes_fail_integrity(): void
    {
        $this->documents('Original');
        File::put($this->fixture.'/legal/privacy-policy/1.0.md', 'Changed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        app(Legal::class)->document('privacy-policy');
    }

    public function test_published_documents_cannot_contain_unfinished_details(): void
    {
        $this->documents('[[PRIVACY CONTACT]]');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('completed details');
        app(Legal::class)->document('privacy-policy');
    }

    public function test_publishing_a_revision_keeps_the_previous_text_accessible(): void
    {
        $this->documents('Original notice');
        $path = $this->fixture.'/legal/manifest.json';
        $catalog = json_decode(File::get($path), true);
        $catalog['privacy-policy']['current'] = '2.0';
        $catalog['privacy-policy']['versions']['2.0'] = [
            'status' => 'published', 'effective_at' => '2026-09-13', 'sha256' => hash('sha256', 'Revised notice'),
        ];
        File::put($this->fixture.'/legal/privacy-policy/2.0.md', 'Revised notice');
        File::put($path, json_encode($catalog));

        $this->get('/privacy-policy')->assertInertia(fn (Assert $page) => $page
            ->where('document.version', '2.0')->where('document.html', "<p>Revised notice</p>\n"));
        $this->get('/privacy-policy/1.0')->assertInertia(fn (Assert $page) => $page
            ->where('document.version', '1.0')->where('document.html', "<p>Original notice</p>\n"));
        $this->artisan('legal:check', ['--published' => true])->assertSuccessful();
    }

    private function documents(string $markdown): void
    {
        $this->fixture = sys_get_temp_dir().'/khronoz-legal-'.Str::uuid();
        $catalog = [];

        foreach (Legal::DOCUMENTS as $slug) {
            File::ensureDirectoryExists($this->fixture.'/legal/'.$slug);
            File::put($this->fixture.'/legal/'.$slug.'/1.0.md', $markdown);
            $catalog[$slug] = ['title' => $slug, 'current' => '1.0', 'versions' => [
                '1.0' => ['status' => 'published', 'effective_at' => '2026-09-12', 'sha256' => hash('sha256', $markdown)],
            ]];
        }

        File::put($this->fixture.'/legal/manifest.json', json_encode($catalog));
        $this->app->instance(Legal::class, new Legal($this->fixture.'/legal'));
    }
}
