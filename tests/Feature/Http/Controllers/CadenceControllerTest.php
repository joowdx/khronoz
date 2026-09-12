<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Cadence;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CadenceControllerTest extends TestCase
{
    public function test_guests_must_sign_in(): void
    {
        $this->get(route('cadences.index'))->assertRedirect(route('login'));
    }

    public function test_agency_management_is_required(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageLedgers);
        $this->post(route('cadences.store'), ['name' => 'Monthly', 'kind' => 'monthly'])->assertForbidden();
    }

    public function test_setting_a_preferred_cadence_replaces_only_this_agencys_preference(): void
    {
        $agency = Agency::factory()->create();
        $old = Cadence::factory()->create(['agency_id' => $agency->id, 'preferred' => true]);
        $other = Cadence::factory()->create(['preferred' => true]);
        $this->actingAsAgency($agency, Permission::ManageAgency);

        $this->post(route('cadences.store'), ['name' => 'Pay periods', 'kind' => 'semimonthly', 'rules' => ['starts' => ['5', '20']], 'preferred' => true])
            ->assertRedirect(route('cadences.index'));

        $this->assertFalse($old->fresh()->preferred);
        $this->assertTrue($other->fresh()->preferred);
        $this->assertDatabaseHas('cadences', ['agency_id' => $agency->id, 'name' => 'Pay periods', 'preferred' => true]);
        $this->get(route('cadences.index'))->assertInertia(fn (Assert $page) => $page->component('cadences/index')->has('cadences', 2));
    }

    public function test_weekly_cadences_use_an_anchor_without_month_days(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageAgency);
        $this->post(route('cadences.store'), ['name' => 'Weekly', 'kind' => 'weekly', 'anchor' => '2026-08-03'])
            ->assertSessionHasNoErrors()->assertRedirect(route('cadences.index'));
        $this->assertDatabaseHas('cadences', ['agency_id' => $agency->id, 'kind' => 'weekly', 'anchor' => '2026-08-03']);
    }

    #[TestWith([[16, 1]])]
    #[TestWith([[1, 1]])]
    #[TestWith([[1, 29]])]
    #[TestWith([[1]])]
    public function test_invalid_month_day_ranges_are_rejected(array $starts): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageAgency);
        $this->post(route('cadences.store'), ['name' => 'Invalid', 'kind' => 'semimonthly', 'rules' => ['starts' => $starts]])
            ->assertSessionHasErrors();
        $this->assertDatabaseMissing('cadences', ['name' => 'Invalid']);
    }

    public function test_retirement_preserves_the_cadence_and_clears_preference(): void
    {
        $agency = Agency::factory()->create();
        $cadence = Cadence::factory()->create(['agency_id' => $agency->id, 'preferred' => true]);
        $this->actingAsAgency($agency, Permission::ManageAgency);
        $this->post(route('cadences.retire', $cadence))->assertRedirect(route('cadences.index'));
        $this->assertNotNull($cadence->fresh()->retired_at);
        $this->assertFalse($cadence->fresh()->preferred);
    }

    public function test_other_agencies_cadences_are_not_found(): void
    {
        $cadence = Cadence::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageAgency);
        $this->get(route('cadences.edit', $cadence))->assertNotFound();
        $this->post(route('cadences.retire', $cadence))->assertNotFound();
        $this->patch(route('cadences.update', $cadence), [])->assertNotFound();
    }
}
