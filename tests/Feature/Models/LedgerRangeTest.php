<?php

namespace Tests\Feature\Models;

use App\Enums\Work;
use App\Models\Agency;
use App\Models\Attestation;
use App\Models\Cadence;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Location;
use App\Models\Policy;
use App\Models\Punch;
use App\Models\Rendition;
use App\Models\Workday;
use Tests\TestCase;

class LedgerRangeTest extends TestCase
{
    public function test_iso_weekly_overtime_belongs_to_the_range_containing_sunday(): void
    {
        $agency = Agency::factory()->create(['settings' => ['overtime_after_weekly' => 48]]);
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        foreach (['2026-08-31', '2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
            Workday::factory()->create(['agency_id' => $agency->id, 'employee_id' => $employee->id, 'date' => $date, 'worked' => 720]);
        }
        Workday::factory()->create(['agency_id' => $agency->id, 'employee_id' => $employee->id, 'date' => '2026-09-04', 'worked' => 480]);
        $first = new Ledger(['agency_id' => $agency->id, 'employee_id' => $employee->id, 'starts' => '2026-08-31', 'ends' => '2026-09-02']);
        $second = new Ledger(['agency_id' => $agency->id, 'employee_id' => $employee->id, 'starts' => '2026-09-03', 'ends' => '2026-09-06']);

        $this->assertSame(0, $first->rangeView()->overtime);
        $this->assertSame(480, $second->rangeView()->overtime);
        $this->assertSame(0, $second->rangeView(Work::Regular)->overtime);
        $this->assertSame(0, Ledger::query()->count());
    }

    public function test_overlapping_ranges_and_work_scopes_can_be_independently_locked(): void
    {
        $first = Ledger::factory()->create();

        $overlap = Ledger::factory()->create([
            'agency_id' => $first->agency_id, 'employee_id' => $first->employee_id,
            'starts' => '2026-08-16', 'ends' => '2026-08-31',
        ]);
        $regular = Ledger::factory()->create([
            'agency_id' => $first->agency_id, 'employee_id' => $first->employee_id,
            'scope' => Work::Regular,
        ]);

        $this->assertModelExists($overlap);
        $this->assertModelExists($regular);
    }

    public function test_exact_range_scope_can_have_only_one_active_revision(): void
    {
        $first = Ledger::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => Ledger::factory()->create([
            'agency_id' => $first->agency_id, 'employee_id' => $first->employee_id, 'revision' => 2,
        ]), 'ledgers_one_active');
    }

    public function test_unlock_preserves_snapshot_and_allows_next_revision(): void
    {
        $first = Ledger::factory()->create();

        $first->update(['unlocked_at' => now(), 'unlocked_by' => $first->locked_by]);
        $second = Ledger::factory()->create([
            'agency_id' => $first->agency_id, 'employee_id' => $first->employee_id, 'revision' => 2,
        ]);

        $this->assertFalse($first->fresh()->locked());
        $this->assertTrue($second->locked());
        $this->assertDatabaseRefuses('P0001', fn () => $first->update(['unlocked_at' => null, 'unlocked_by' => null]));
    }

    public function test_attested_revision_cannot_unlock(): void
    {
        $first = Ledger::factory()->create();
        Attestation::factory()->create(['agency_id' => $first->agency_id, 'ledger_id' => $first->id]);

        $this->assertDatabaseRefuses('P0001', fn () => $first->update(['unlocked_at' => now(), 'unlocked_by' => $first->locked_by]));
    }

    public function test_locked_identity_and_range_cannot_be_rewritten(): void
    {
        $first = Ledger::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => $first->update(['identity' => ['name' => 'Changed']]));
        $this->assertDatabaseRefuses('P0001', fn () => $first->update(['starts' => '2026-07-31']));
    }

    public function test_workday_facts_are_frozen_by_any_covering_active_range(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-20']);
        $first = Ledger::factory()->create([
            'agency_id' => $workday->agency_id, 'employee_id' => $workday->employee_id,
        ]);
        $overlap = Ledger::factory()->create([
            'agency_id' => $workday->agency_id, 'employee_id' => $workday->employee_id,
            'starts' => '2026-08-16', 'ends' => '2026-08-31',
        ]);

        $first->update(['unlocked_at' => now(), 'unlocked_by' => $first->locked_by]);
        $this->assertDatabaseRefuses('P0001', fn () => $workday->update(['worked' => 30]));
        $overlap->update(['unlocked_at' => now(), 'unlocked_by' => $overlap->locked_by]);
        $workday->update(['worked' => 30]);

        $this->assertSame(30, $workday->fresh()->worked);
    }

    public function test_cannot_lock_before_final_manila_date(): void
    {
        $this->assertDatabaseRefuses('P0001', fn () => Ledger::factory()->create([
            'starts' => '2099-01-01', 'ends' => '2099-01-31', 'locked_at' => '2099-02-01 12:00:00',
        ]), 'final Manila date');
    }

    public function test_cannot_lock_while_overnight_punch_is_due(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-31']);
        Punch::factory()->missed()->create([
            'agency_id' => $workday->agency_id, 'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id, 'kind' => 'out', 'expected_at' => '2026-09-01 08:00:00',
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => Ledger::factory()->create([
            'agency_id' => $workday->agency_id, 'employee_id' => $workday->employee_id,
            'locked_at' => '2026-08-31 23:00:00',
        ]), 'punch is still due');
    }

    public function test_employee_cadence_cannot_cross_agencies(): void
    {
        $cadence = Cadence::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Employee::factory()->create(['cadence_id' => $cadence->id]));
    }

    public function test_preferred_cadence_must_be_unique_and_active(): void
    {
        $first = Cadence::factory()->create(['preferred' => true]);

        $this->assertDatabaseRefuses('23505', fn () => Cadence::factory()->create(['agency_id' => $first->agency_id, 'preferred' => true]));
        $this->assertDatabaseRefuses('23514', fn () => $first->update(['retired_at' => now()]));
    }

    public function test_range_configuration_constraints_reject_ambiguous_records(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => Cadence::factory()->create([
            'kind' => 'monthly',
            'anchor' => '2026-01-01',
        ]));
        $this->assertDatabaseRefuses('23514', fn () => Policy::factory()->create([
            'roles' => ['employee', 'employee'],
        ]));
        $this->assertDatabaseRefuses('23514', fn () => Ledger::factory()->create([
            'starts' => '2026-08-01',
            'ends' => '2026-09-01',
        ]));
        $this->assertDatabaseRefuses('23514', fn () => Rendition::factory()->create([
            'template' => 'unsupported',
        ]));
    }

    public function test_unstored_rendition_keeps_public_token_without_document(): void
    {
        $rendition = Rendition::factory()->create();

        $this->assertNull($rendition->document_id);
        $this->assertSame(48, strlen($rendition->token));
        $this->assertDatabaseRefuses('42501', fn () => $rendition->update(['snapshot' => ['changed' => true]]));
    }

    public function test_document_identity_is_immutable_and_primary_location_must_be_verified(): void
    {
        $document = Document::factory()->create();

        $this->assertDatabaseRefuses('42501', fn () => $document->update(['digest' => str_repeat('0', 64)]));
        $this->assertDatabaseRefuses('23514', fn () => Location::factory()->create([
            'agency_id' => $document->agency_id, 'document_id' => $document->id, 'primary' => true,
        ]));
        $location = Location::factory()->create([
            'agency_id' => $document->agency_id, 'document_id' => $document->id, 'primary' => true, 'verified_at' => now(),
        ]);
        $this->assertDatabaseRefuses('23505', fn () => Location::factory()->create([
            'agency_id' => $document->agency_id, 'document_id' => $document->id, 'primary' => true, 'verified_at' => now(),
        ]));

        $this->assertModelExists($location);
    }
}
