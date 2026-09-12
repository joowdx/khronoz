<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\AttestLedger;
use App\Actions\LockLedger;
use App\Enums\Permission;
use App\Enums\Premium;
use App\Enums\RenditionStatus;
use App\Enums\Work;
use App\Jobs\GenerateLedgerDocument;
use App\Models\Agency;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Location;
use App\Models\Policy;
use App\Models\Workday;
use App\Support\LedgerPdf;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class LedgerPdfControllerTest extends TestCase
{
    public function test_current_download_renders_the_requested_range_without_creating_a_ledger(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ViewLedgers);
        $this->mock(LedgerPdf::class, function (MockInterface $mock): void {
            $mock->shouldReceive('render')->once()->withArgs(fn (array $snapshot, string $template): bool => $snapshot['ledger']['starts'] === '2026-08-16' && $snapshot['ledger']['ends'] === '2026-08-31'
                && $snapshot['ledger']['scope'] === 'regular' && $snapshot['attestations'] === [] && $template === 'form48'
            )->andReturn('%PDF-current');
        });
        $this->get(route('employees.ledger.download', [$employee, 'starts' => '2026-08-01', 'ends' => '2026-08-31', 'period' => 'second', 'work' => 'regular']))
            ->assertHeader('Content-Type', 'application/pdf')->assertContent('%PDF-current');
        $this->assertDatabaseCount('ledgers', 0);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_current_download_refuses_more_than_31_days(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ViewLedgers);
        $this->get(route('employees.ledger.download', [$employee, 'starts' => '2026-08-01', 'ends' => '2026-09-01']))
            ->assertSessionHasErrors(['ends' => 'Choose no more than 31 days.']);
    }

    public function test_current_download_can_filter_detail_rows_without_storing_a_report(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ViewLedgers);
        Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-08-12',
            'night' => 120,
        ]);
        Workday::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-08-13',
            'premium' => Premium::Rest,
        ]);
        $this->mock(LedgerPdf::class, function (MockInterface $mock): void {
            $mock->shouldReceive('render')->once()->withArgs(fn (array $snapshot): bool => count($snapshot['workdays']) === 1
                && $snapshot['workdays'][0]['date'] === '2026-08-12'
                && $snapshot['filters'] === [['value' => 'night', 'label' => 'Night work']]
            )->andReturn('%PDF-filtered');
        });

        $this->get(route('employees.ledger.download', [$employee,
            'starts' => '2026-08-01', 'ends' => '2026-08-31', 'days' => ['night'],
        ]))->assertContent('%PDF-filtered');

        $this->assertDatabaseCount('ledgers', 0);
        $this->assertDatabaseCount('renditions', 0);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_current_download_rejects_unknown_detail_filters(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ViewLedgers);

        $this->get(route('employees.ledger.download', [$employee,
            'starts' => '2026-08-01', 'ends' => '2026-08-31', 'days' => ['payroll'],
        ]))->assertSessionHasErrors('days.0');
    }

    public function test_current_download_refuses_foreign_employees(): void
    {
        $employee = Employee::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewLedgers);
        $this->get(route('employees.ledger.download', [$employee, 'starts' => '2026-08-01', 'ends' => '2026-08-31']))->assertNotFound();
    }

    public function test_unstored_download_uses_the_immutable_rendition_and_token(): void
    {
        [$ledger, $rendition] = $this->attested();
        $snapshot = $rendition->snapshot;
        $token = $rendition->token;
        $this->mock(LedgerPdf::class, function (MockInterface $mock) use ($snapshot, $token): void {
            $mock->shouldReceive('render')->once()->withArgs(fn (array $rendered, string $template, string $renderToken): bool => $rendered['ledger'] === $snapshot['ledger']
                && $rendered['attestations'] === $snapshot['attestations']
                && is_string($rendered['document']['generated_at'] ?? null)
                && $template === 'form48'
                && $renderToken === $token
            )->andReturn('%PDF-frozen');
        });
        $this->get(route('ledgers.download', $ledger))->assertContent('%PDF-frozen');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_canonical_download_refuses_different_filters(): void
    {
        [$ledger] = $this->attested();
        $this->get(route('ledgers.download', [$ledger, 'work' => 'regular']))->assertSessionHasErrors('form');
        $this->get(route('ledgers.download', [$ledger, 'days' => ['night']]))->assertSessionHasErrors('form');
    }

    public function test_historical_download_returns_exact_stored_bytes_without_location_metadata(): void
    {
        Storage::fake('s3');
        Queue::fake([GenerateLedgerDocument::class]);
        [$ledger, $rendition] = $this->attested(true);
        $bytes = '%PDF-canonical exact bytes';
        $document = Document::factory()->create(['agency_id' => $ledger->agency_id, 'bytes' => strlen($bytes), 'algorithm' => 'sha256', 'digest' => hash('sha256', $bytes)]);
        Location::factory()->create(['agency_id' => $ledger->agency_id, 'document_id' => $document->id, 'store' => 'archive', 'key' => 'private/archive.pdf', 'verified_at' => now(), 'primary' => true]);
        Storage::disk('s3')->put('private/archive.pdf', $bytes);
        $rendition->update(['status' => RenditionStatus::Ready, 'document_id' => $document->id, 'generated_at' => now()]);
        $this->get(route('ledgers.renditions.download', [$ledger, $rendition]))->assertContent($bytes);
        $this->get(route('ledgers.verify', $rendition->token))->assertInertia(fn (Assert $page) => $page
            ->where('document.digest', hash('sha256', $bytes))->missing('document.locations')
            ->where('download_url', route('ledgers.renditions.download', [$ledger, $rendition])));
        Storage::disk('s3')->assertExists('private/archive.pdf');
        Queue::assertPushed(GenerateLedgerDocument::class);
    }

    public function test_a_rendition_cannot_be_downloaded_through_another_parent(): void
    {
        [$ledger, $rendition] = $this->attested();
        $other = Ledger::factory()->create(['agency_id' => $ledger->agency_id]);
        $this->get(route('ledgers.renditions.download', [$other, $rendition]))->assertNotFound();
    }

    public function test_a_failed_archive_can_be_retried_by_a_manager(): void
    {
        Queue::fake([GenerateLedgerDocument::class]);
        [$ledger, $rendition] = $this->attested(true);
        $rendition->update(['status' => RenditionStatus::Failed, 'failed_at' => now(), 'error' => 'Rendering failed']);
        $this->post(route('ledgers.renditions.retry', [$ledger, $rendition]))->assertSessionHas('success');
        $this->assertSame(RenditionStatus::Pending, $rendition->fresh()->status);
        Queue::assertPushed(GenerateLedgerDocument::class, 2);
    }

    private function attested(bool $archive = false): array
    {
        $agency = Agency::factory()->create(['settings' => ['ledger_archiving' => $archive]]);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $actor = $this->actingAsAgency($agency, Permission::ManageLedgers, Permission::AttestLedgers);
        Policy::factory()->create(['agency_id' => $agency->id, 'roles' => ['timekeeper']]);
        $this->withTenant($agency);
        $ledger = app(LockLedger::class)->handle($employee, '2026-08-01', '2026-08-31', Work::All, $actor);
        app(AttestLedger::class)->handle($ledger, $actor);

        return [$ledger, $ledger->renditions()->firstOrFail()];
    }
}
