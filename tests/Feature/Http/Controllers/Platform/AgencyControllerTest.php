<?php

namespace Tests\Feature\Http\Controllers\Platform;

use App\Enums\Preset;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgencyControllerTest extends TestCase
{
    public function test_agency_users_cannot_reach_the_platform_area(): void
    {
        $this->actingAs(User::factory()->acceptedLegal()->preset(Preset::Admin)->create())->get(route('platform.agencies.index'))->assertForbidden();
    }

    public function test_platform_user_lists_agencies_without_the_platform_row(): void
    {
        Agency::factory()->count(3)->create();

        $this->actingAs(User::factory()->acceptedLegal()->platform()->create())->get(route('platform.agencies.index'))
            ->assertInertia(fn (Assert $page) => $page->component('platform/agencies/index')->has('agencies', 3));
    }

    public function test_store_creates_an_agency_and_redirects(): void
    {
        $this->actingAs(User::factory()->acceptedLegal()->platform()->create())
            ->post(route('platform.agencies.store'), ['code' => 'DOH', 'name' => 'Department of Health'])
            ->assertRedirect(route('platform.agencies.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('agencies', ['code' => 'DOH', 'platform' => false]);
    }

    public function test_store_requires_a_unique_code(): void
    {
        Agency::factory()->create(['code' => 'DOH']);

        $this->actingAs(User::factory()->acceptedLegal()->platform()->create())
            ->post(route('platform.agencies.store'), ['code' => 'doh', 'name' => 'Dup'])
            ->assertSessionHasErrors(['code' => 'Already taken']);
    }

    public function test_edit_renders_the_agency_being_edited(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs(User::factory()->acceptedLegal()->platform()->create())->get(route('platform.agencies.edit', $agency))
            ->assertInertia(fn (Assert $page) => $page->component('platform/agencies/edit')
                ->where('agency.id', $agency->id)
                ->where('agency.code', $agency->code));
    }

    public function test_update_persists_changes_and_redirects_with_success(): void
    {
        $agency = Agency::factory()->create(['code' => 'DOH', 'name' => 'Department of Health']);

        $this->actingAs(User::factory()->acceptedLegal()->platform()->create())
            ->put(route('platform.agencies.update', $agency), ['code' => 'MOH', 'name' => 'Ministry of Health'])
            ->assertRedirect(route('platform.agencies.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('agencies', ['id' => $agency->id, 'code' => 'MOH', 'name' => 'Ministry of Health']);
    }

    public function test_updating_an_agency_without_changing_its_code_succeeds(): void
    {
        $agency = Agency::factory()->create(['code' => 'DOH', 'name' => 'Department of Health']);

        $this->actingAs(User::factory()->acceptedLegal()->platform()->create())
            ->put(route('platform.agencies.update', $agency), ['code' => $agency->code, 'name' => 'Renamed Department of Health'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Department of Health', $agency->refresh()->name);
    }

    public function test_a_platform_with_no_agencies_yet_renders_an_empty_list(): void
    {
        $this->actingAs(User::factory()->acceptedLegal()->platform()->create())->get(route('platform.agencies.index'))
            ->assertInertia(fn (Assert $page) => $page->has('agencies', 0)
                ->where('pagination.total', 0)
                ->where('pagination.from', null)
                ->where('filters.search', ''));
    }

    public function test_search_matches_a_code_or_a_name_case_insensitively(): void
    {
        Agency::factory()->create(['code' => 'DOH', 'name' => 'Department of Health']);
        Agency::factory()->create(['code' => 'PHO', 'name' => 'Provincial Health Office']);
        Agency::factory()->create(['code' => 'DPWH', 'name' => 'Public Works']);

        $superuser = User::factory()->acceptedLegal()->platform()->create();

        // By code, lower case: the column is searched with ILIKE.
        $this->actingAs($superuser)->get(route('platform.agencies.index', ['search' => 'doh']))
            ->assertInertia(fn (Assert $page) => $page->has('agencies', 1)
                ->where('agencies.0.code', 'DOH')
                // The query comes back as a prop so the input keeps what was
                // typed and the URL stays the shareable state.
                ->where('filters.search', 'doh'));

        // By name, matching two rows across two different codes.
        $this->actingAs($superuser)->get(route('platform.agencies.index', ['search' => 'health']))
            ->assertInertia(fn (Assert $page) => $page->has('agencies', 2));
    }

    public function test_the_list_carries_a_users_count_per_agency(): void
    {
        $busy = Agency::factory()->create(['code' => 'AAA', 'name' => 'Aaa Office']);
        User::factory()->count(2)->for($busy)->create();
        $empty = Agency::factory()->create(['code' => 'ZZZ', 'name' => 'Zzz Office']);

        // Default order is name ascending, so Aaa comes first.
        $this->actingAs(User::factory()->acceptedLegal()->platform()->create())->get(route('platform.agencies.index'))
            ->assertInertia(fn (Assert $page) => $page->has('agencies', 2)
                ->where('agencies.0.id', $busy->id)
                ->where('agencies.0.users_count', 2)
                ->where('agencies.1.id', $empty->id)
                ->where('agencies.1.users_count', 0));
    }

    public function test_the_users_column_is_one_aggregate_not_a_query_per_row(): void
    {
        Agency::factory()->count(5)->create()->each(fn (Agency $agency) => User::factory()->for($agency)->create());

        DB::enableQueryLog();

        $this->actingAs(User::factory()->acceptedLegal()->platform()->create())->get(route('platform.agencies.index'))->assertOk();

        $counts = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'as "users_count"'))
            ->count();

        DB::disableQueryLog();

        // withCount folds the count into the list query itself; a per-row count
        // would be five more.
        $this->assertSame(1, $counts);
    }

    public function test_sort_is_a_whitelist_and_orders_by_the_users_count(): void
    {
        $busy = Agency::factory()->create(['code' => 'AAA', 'name' => 'Aaa']);
        User::factory()->count(2)->for($busy)->create();
        $quiet = Agency::factory()->create(['code' => 'ZZZ', 'name' => 'Zzz']);

        $superuser = User::factory()->acceptedLegal()->platform()->create();

        $this->actingAs($superuser)->get(route('platform.agencies.index', ['sort' => 'users', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page->where('agencies.0.id', $busy->id)
                ->where('agencies.1.id', $quiet->id)
                ->where('filters.sort', 'users')
                ->where('filters.direction', 'desc'));

        // Anything not on the whitelist falls back to the default, rather than
        // reaching the ORDER BY: the value arrives in the query string.
        $this->actingAs($superuser)->get(route('platform.agencies.index', ['sort' => 'settings', 'direction' => 'sideways']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.sort', 'name')
                ->where('filters.direction', 'asc')
                ->where('agencies.0.id', $busy->id));
    }

    public function test_the_list_is_paginated_and_reports_its_range(): void
    {
        Agency::factory()->count(22)->create();

        $superuser = User::factory()->acceptedLegal()->platform()->create();

        $this->actingAs($superuser)->get(route('platform.agencies.index'))
            ->assertInertia(fn (Assert $page) => $page->has('agencies', 20)
                ->where('pagination.from', 1)
                ->where('pagination.to', 20)
                ->where('pagination.total', 22)
                ->where('pagination.previous', null)
                ->where('pagination.next', fn (?string $url) => $url !== null));

        $this->actingAs($superuser)->get(route('platform.agencies.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('agencies', 2)
                ->where('pagination.next', null));
    }

    public function test_the_entered_agency_is_the_shared_agency_prop_on_the_list(): void
    {
        $agency = Agency::factory()->create();
        $superuser = User::factory()->acceptedLegal()->platform()->create();

        $this->actingAs($superuser)->post(route('platform.agencies.enter', $agency));

        $this->actingAs($superuser)->get(route('platform.agencies.index'))
            ->assertInertia(fn (Assert $page) => $page->where('agency.id', $agency->id)
                ->where('agencies.0.id', $agency->id));
    }

    public function test_no_platform_route_is_reachable_by_an_agency_user(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->acceptedLegal()->preset(Preset::Admin)->create();

        $this->actingAs($user)->get(route('platform.agencies.create'))->assertForbidden();
        $this->actingAs($user)->post(route('platform.agencies.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->actingAs($user)->get(route('platform.agencies.edit', $agency))->assertForbidden();
        $this->actingAs($user)->put(route('platform.agencies.update', $agency), ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->actingAs($user)->post(route('platform.agencies.enter', $agency))->assertForbidden();
        $this->actingAs($user)->delete(route('platform.agencies.leave'))->assertForbidden();

        $this->assertDatabaseMissing('agencies', ['code' => 'X']);
    }

    public function test_the_platform_row_cannot_be_edited_or_updated_by_id(): void
    {
        $superuser = User::factory()->acceptedLegal()->platform()->create();

        $this->actingAs($superuser)->get(route('platform.agencies.edit', Agency::platform()->id))->assertNotFound();

        $this->actingAs($superuser)->put(route('platform.agencies.update', Agency::platform()->id), ['code' => 'PLT', 'name' => 'Renamed'])->assertNotFound();
    }
}
