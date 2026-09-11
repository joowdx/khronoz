<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Sync;
use App\Models\Terminal;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SyncControllerTest extends TestCase
{
    public function test_viewing_requires_terminals_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewCalendar);

        $this->get(route('syncs.index'))->assertForbidden();
    }

    public function test_the_index_lists_this_agencys_runs_only(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);

        Sync::factory()->on(Terminal::factory()->create(['agency_id' => $agency->id]))->create(['reference' => 'mine.dat']);
        Sync::factory()->create(['reference' => 'theirs.dat']);

        $this->get(route('syncs.index'))->assertInertia(
            fn (Assert $page) => $page
                ->component('syncs/index')
                ->has('syncs', 1)
                ->where('syncs.0.reference', 'mine.dat')
        );
    }

    /**
     * The rows worth finding. A file naming two devices is refused whole as
     * probable tampering (decision 44), and this row is the only lasting
     * trace of the attempt — without it, a refusal is a flash message the
     * second attempt looks identical to.
     */
    public function test_refused_runs_can_be_filtered_to_and_carry_their_reason(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);
        $terminal = Terminal::factory()->create(['agency_id' => $agency->id]);

        Sync::factory()->on($terminal)->completed()->create(['reference' => 'good.dat']);
        Sync::factory()->on($terminal)->failed('The file names more than one device (7, 3).')
            ->create(['reference' => 'tampered.dat']);

        $this->get(route('syncs.index', ['failed' => 1]))->assertInertia(
            fn (Assert $page) => $page
                ->has('syncs', 1)
                ->where('syncs.0.reference', 'tampered.dat')
                ->where('syncs.0.status', 'failed')
                ->where('syncs.0.error', 'The file names more than one device (7, 3).')
        );
    }

    public function test_runs_can_be_filtered_by_device(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);
        $lobby = Terminal::factory()->create(['agency_id' => $agency->id]);
        $annex = Terminal::factory()->create(['agency_id' => $agency->id]);

        Sync::factory()->on($lobby)->create(['reference' => 'lobby.dat']);
        Sync::factory()->on($annex)->create(['reference' => 'annex.dat']);

        $this->get(route('syncs.index', ['terminal' => $lobby->id]))->assertInertia(
            fn (Assert $page) => $page->has('syncs', 1)->where('syncs.0.reference', 'lobby.dat')
        );
    }

    /** The counters the page prints are the ones the CHECK balances. */
    public function test_the_counters_are_sent_as_stored_and_add_up(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);

        Sync::factory()->on(Terminal::factory()->create(['agency_id' => $agency->id]))
            ->completed(accepted: 40, duplicates: 8, rejected: 2)->create();

        $this->get(route('syncs.index'))->assertInertia(
            fn (Assert $page) => $page
                ->where('syncs.0.received', 50)
                ->where('syncs.0.accepted', 40)
                ->where('syncs.0.duplicates', 8)
                ->where('syncs.0.rejected', 2)
        );
    }

    /** Nothing here can be written: the app role has no DELETE and there is no write route at all. */
    public function test_there_is_no_route_that_changes_a_run(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'syncs'))
            ->flatMap(fn ($route) => $route->methods())
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['GET', 'HEAD'], $routes);
    }
}
