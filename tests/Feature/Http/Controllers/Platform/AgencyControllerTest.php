<?php

namespace Tests\Feature\Http\Controllers\Platform;

use App\Enums\Preset;
use App\Models\Agency;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgencyControllerTest extends TestCase
{
    public function test_agency_users_cannot_reach_the_platform_area(): void
    {
        $this->actingAs(User::factory()->preset(Preset::Admin)->create())->get(route('platform.agencies.index'))->assertForbidden();
    }

    public function test_platform_user_lists_agencies_without_the_platform_row(): void
    {
        Agency::factory()->count(3)->create();

        $this->actingAs(User::factory()->platform()->create())->get(route('platform.agencies.index'))
            ->assertInertia(fn (Assert $page) => $page->component('platform/agencies/index')->has('agencies', 3));
    }

    public function test_store_creates_an_agency_and_redirects(): void
    {
        $this->actingAs(User::factory()->platform()->create())
            ->post(route('platform.agencies.store'), ['code' => 'DOH', 'name' => 'Department of Health'])
            ->assertRedirect(route('platform.agencies.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('agencies', ['code' => 'DOH', 'platform' => false]);
    }

    public function test_store_requires_a_unique_code(): void
    {
        Agency::factory()->create(['code' => 'DOH']);

        $this->actingAs(User::factory()->platform()->create())
            ->post(route('platform.agencies.store'), ['code' => 'doh', 'name' => 'Dup'])
            ->assertSessionHasErrors(['code' => 'Already taken']);
    }

    public function test_edit_renders_the_agency_being_edited(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs(User::factory()->platform()->create())->get(route('platform.agencies.edit', $agency))
            ->assertInertia(fn (Assert $page) => $page->component('platform/agencies/edit')
                ->where('agency.id', $agency->id)
                ->where('agency.code', $agency->code));
    }

    public function test_update_persists_changes_and_redirects_with_success(): void
    {
        $agency = Agency::factory()->create(['code' => 'DOH', 'name' => 'Department of Health']);

        $this->actingAs(User::factory()->platform()->create())
            ->put(route('platform.agencies.update', $agency), ['code' => 'MOH', 'name' => 'Ministry of Health'])
            ->assertRedirect(route('platform.agencies.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('agencies', ['id' => $agency->id, 'code' => 'MOH', 'name' => 'Ministry of Health']);
    }

    /**
     * The single most valuable test in this file. UpdateAgencyRequest's
     * Rule::unique('agencies', 'code')->ignore($this->route('agency')) exists
     * precisely so posting an agency's own unchanged code back to itself does
     * not collide with its own row. Do not "simplify" this into changing both
     * fields — the unchanged code is the point: without ->ignore(), this exact
     * request would fail validation against the agency's own row.
     */
    public function test_updating_an_agency_without_changing_its_code_succeeds(): void
    {
        $agency = Agency::factory()->create(['code' => 'DOH', 'name' => 'Department of Health']);

        $this->actingAs(User::factory()->platform()->create())
            ->put(route('platform.agencies.update', $agency), ['code' => $agency->code, 'name' => 'Renamed Department of Health'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Department of Health', $agency->refresh()->name);
    }

    public function test_the_platform_row_cannot_be_edited_or_updated_by_id(): void
    {
        $superuser = User::factory()->platform()->create();

        $this->actingAs($superuser)->get(route('platform.agencies.edit', Agency::platform()->id))->assertNotFound();

        $this->actingAs($superuser)->put(route('platform.agencies.update', Agency::platform()->id), ['code' => 'PLT', 'name' => 'Renamed'])->assertNotFound();
    }
}
