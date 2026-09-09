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
            ->assertSessionHasErrors(['code' => 'The code has already been taken.']);
    }
}
