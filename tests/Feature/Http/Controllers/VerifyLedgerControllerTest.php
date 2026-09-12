<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\AttestLedger;
use App\Actions\LockLedger;
use App\Enums\Permission;
use App\Enums\Work;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\Rendition;
use App\Models\User;
use App\Tenancy\Tenant;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VerifyLedgerControllerTest extends TestCase
{
    public function test_anonymous_verification_renders_the_frozen_ledger_without_a_document(): void
    {
        $rendition = $this->rendition();
        $name = $rendition->snapshot['employee']['name'];
        Employee::findOrFail($rendition->snapshot['employee']['id'])->update(['first_name' => 'Renamed']);

        $this->get(route('ledgers.verify', $rendition->token))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertInertia(fn (Assert $page) => $page->component('ledgers/verify')
                ->where('snapshot.employee.name', $name)->where('snapshot.ledger.starts', '2026-08-01')
                ->where('document', null)->where('download_url', null)->missing('locations'));
    }

    public function test_verification_temporarily_enters_the_renditions_tenant(): void
    {
        $other = Agency::factory()->create();
        $rendition = $this->rendition();
        app(Tenant::class)->set($other);

        $this->get(route('ledgers.verify', $rendition->token))->assertInertia(fn (Assert $page) => $page
            ->where('snapshot.agency.id', $rendition->agency_id));

        $this->assertSame($other->id, app(Tenant::class)->id());
    }

    public function test_unknown_tokens_return_not_found_and_there_is_no_listing(): void
    {
        $this->get(route('ledgers.verify', 'not-a-token'))->assertNotFound();
        $this->get('/verify/ledgers')->assertNotFound();
    }

    public function test_superseded_renditions_still_show_the_frozen_ledger(): void
    {
        $rendition = $this->rendition();
        $rendition->update(['superseded_at' => now()]);
        $this->get(route('ledgers.verify', $rendition->token))->assertInertia(fn (Assert $page) => $page
            ->where('superseded_at', $rendition->superseded_at->toDateTimeString())
            ->where('snapshot.ledger.id', $rendition->ledger_id));
    }

    public function test_verification_is_rate_limited(): void
    {
        $rendition = $this->rendition();
        foreach (range(1, 30) as $attempt) {
            $this->get(route('ledgers.verify', $rendition->token))->assertOk();
        }
        $this->get(route('ledgers.verify', $rendition->token))->assertTooManyRequests();
    }

    private function rendition(): Rendition
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $user = User::factory()->forAgency($agency)->permissions(Permission::ManageLedgers, Permission::AttestLedgers)->create();
        Policy::factory()->create(['agency_id' => $agency->id, 'template' => 'plain', 'roles' => ['timekeeper']]);
        $this->withTenant($agency);
        $ledger = app(LockLedger::class)->handle($employee, '2026-08-01', '2026-08-31', Work::All, $user);
        app(AttestLedger::class)->handle($ledger, $user);

        return $ledger->renditions()->firstOrFail();
    }
}
