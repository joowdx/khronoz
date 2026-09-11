<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Enrollment;
use App\Models\Terminal;
use App\Models\Timelog;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TimelogControllerTest extends TestCase
{
    public function test_viewing_requires_terminals_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewCalendar);

        $this->get(route('timelogs.index'))->assertForbidden();
    }

    public function test_the_index_lists_this_agencys_timelogs_only(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);

        $mine = Terminal::factory()->create(['agency_id' => $agency->id]);
        Timelog::factory()->on($mine)->create(['uid' => '0042']);
        Timelog::factory()->create(['uid' => '9999']);

        $this->get(route('timelogs.index'))->assertInertia(
            fn (Assert $page) => $page
                ->component('timelogs/index')
                ->has('timelogs', 1)
                ->where('timelogs.0.uid', '0042')
        );
    }

    /**
     * The filter this screen exists for: a punch whose uid matched no
     * enrollment on its date belongs to nobody, and nobody is looking for it.
     */
    public function test_the_unresolved_filter_finds_punches_that_belong_to_nobody(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);

        $enrollment = Enrollment::factory()->create(['agency_id' => $agency->id, 'uid' => '0001']);
        Timelog::factory()->resolving($enrollment)->create();

        $this->withTenant($agency);
        $terminal = Terminal::findOrFail($enrollment->terminal_id);
        $orphan = Timelog::factory()->on($terminal)->create(['uid' => '9999']);

        $this->get(route('timelogs.index', ['unresolved' => 1]))->assertInertia(
            fn (Assert $page) => $page
                ->has('timelogs', 1)
                ->where('timelogs.0.id', $orphan->id)
                ->where('timelogs.0.employee', null)
                ->where('filters.unresolved', true)
        );
    }

    /**
     * Voided rows are **included by default**, unlike a soft delete. Nothing
     * is ever hidden here (03-terminals.md rule 1); the filter narrows *to*
     * them.
     */
    public function test_voided_punches_are_listed_by_default_and_can_be_filtered_to(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);

        $terminal = Terminal::factory()->create(['agency_id' => $agency->id]);
        Timelog::factory()->on($terminal)->create(['uid' => '0001']);
        Timelog::factory()->on($terminal)->voided()->create(['uid' => '0002']);

        $this->get(route('timelogs.index'))->assertInertia(fn (Assert $page) => $page->has('timelogs', 2));

        $this->get(route('timelogs.index', ['voided' => 1]))->assertInertia(
            fn (Assert $page) => $page->has('timelogs', 1)->where('timelogs.0.uid', '0002')
        );
    }

    public function test_the_list_can_be_filtered_by_device_and_by_date(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);

        $lobby = Terminal::factory()->create(['agency_id' => $agency->id]);
        $annex = Terminal::factory()->create(['agency_id' => $agency->id]);
        Timelog::factory()->on($lobby)->create(['uid' => '0001', 'time' => '2026-09-01 08:00:00']);
        Timelog::factory()->on($lobby)->create(['uid' => '0001', 'time' => '2026-09-20 08:00:00']);
        Timelog::factory()->on($annex)->create(['uid' => '0002', 'time' => '2026-09-10 08:00:00']);

        $this->get(route('timelogs.index', ['terminal' => $lobby->id]))
            ->assertInertia(fn (Assert $page) => $page->has('timelogs', 2));

        $this->get(route('timelogs.index', ['from' => '2026-09-05', 'to' => '2026-09-15']))
            ->assertInertia(fn (Assert $page) => $page->has('timelogs', 1)->where('timelogs.0.uid', '0002'));
    }

    /** Decision 42 at the filter: `007` must not match `7`. */
    public function test_the_device_user_filter_matches_the_string_exactly(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);

        $terminal = Terminal::factory()->create(['agency_id' => $agency->id]);
        Timelog::factory()->on($terminal)->create(['uid' => '007', 'time' => '2026-09-01 08:00:00']);
        Timelog::factory()->on($terminal)->create(['uid' => '7', 'time' => '2026-09-01 09:00:00']);

        $this->get(route('timelogs.index', ['uid' => '007']))
            ->assertInertia(fn (Assert $page) => $page->has('timelogs', 1)->where('timelogs.0.uid', '007'));
    }

    /** A mangled query string must not leave the list filtered by something the picker cannot show. */
    public function test_an_unknown_terminal_filter_is_reported_as_unset(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);
        Timelog::factory()->on(Terminal::factory()->create(['agency_id' => $agency->id]))->create();

        $this->get(route('timelogs.index', ['terminal' => 'nonsense']))->assertInertia(
            fn (Assert $page) => $page->where('filters.terminal', '')->has('timelogs', 1)
        );
    }

    public function test_voiding_requires_terminals_manage(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);
        $timelog = Timelog::factory()->on(Terminal::factory()->create(['agency_id' => $agency->id]))->create();

        $this->patch(route('timelogs.void', $timelog), ['reason' => 'Duplicate scan'])->assertForbidden();
    }

    /** The row stays and stays visible — voiding marks it, it does not remove it. */
    public function test_a_punch_can_be_voided_and_remains(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $timelog = Timelog::factory()->on(Terminal::factory()->create(['agency_id' => $agency->id]))->create();

        $this->patch(route('timelogs.void', $timelog), ['reason' => 'Duplicate scan'])
            ->assertSessionHas('success');

        $voided = $timelog->fresh();

        $this->assertNotNull($voided->voided_at);
        $this->assertSame('Duplicate scan', $voided->reason);
        $this->assertDatabaseHas('timelogs', ['id' => $timelog->id]);
    }

    /**
     * A void says who performed it.
     *
     * It could not before: the app role's UPDATE was granted on
     * `(voided_at, reason)` only, so writing an actor failed **42501**. The
     * strike-out of a pay record was the one act in the application nobody
     * could be held to. `voided_by` is a second column rather than a reuse of
     * `user_id`, which stays out of the grant so a void can never rewrite
     * whose punch it was.
     */
    public function test_a_void_records_who_performed_it(): void
    {
        $agency = Agency::factory()->create();
        $user = $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = Terminal::factory()->create(['agency_id' => $agency->id]);
        $timelog = Timelog::factory()->on($terminal)->create();

        $this->patch(route('timelogs.void', $timelog), ['reason' => 'Duplicate scan'])
            ->assertSessionHas('success');

        $this->assertSame($user->id, $timelog->fresh()->voided_by);

        // And the screen says so. An attribution nobody can read is not one.
        $this->get(route('timelogs.index', ['voided' => 1]))->assertInertia(
            fn (Assert $page) => $page
                ->where('timelogs.0.voider.id', $user->id)
                ->where('timelogs.0.voider.name', $user->name)
        );
    }

    /**
     * A void is final, and a second one cannot erase the first.
     *
     * `voided_at`, `reason` and the actor were all inside the column grant, so
     * re-voiding was a legal UPDATE that overwrote every one of them — the
     * audit record erased itself and the second void looked like the only one
     * there had ever been. Reproduced before the fix: reason became "oops" and
     * `voided_at` moved. No CHECK can see OLD, so `timelogs_void_is_final` is
     * a trigger, and its P0001 is translated rather than surfacing as a 500.
     */
    public function test_a_voided_punch_cannot_be_voided_again(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = Terminal::factory()->create(['agency_id' => $agency->id]);
        $timelog = Timelog::factory()->on($terminal)->voided('Duplicate scan')->create();

        $first = $timelog->fresh();

        $this->patch(route('timelogs.void', $timelog), ['reason' => 'oops'])
            ->assertSessionHas('error');

        $again = $timelog->fresh();

        $this->assertSame('Duplicate scan', $again->reason);
        $this->assertSame($first->voided_at->toDateTimeString(), $again->voided_at->toDateTimeString());
        $this->assertSame($first->voided_by, $again->voided_by);
    }

    /** `timelogs_void_needs_reason`, mirrored so the refusal lands on the field. */
    public function test_a_void_without_a_reason_is_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $timelog = Timelog::factory()->on(Terminal::factory()->create(['agency_id' => $agency->id]))->create();

        $this->patch(route('timelogs.void', $timelog), ['reason' => ''])->assertSessionHasErrors('reason');

        $this->assertNull($timelog->fresh()->voided_at);
    }

    public function test_another_agencys_timelog_cannot_be_voided(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageTerminals);
        $theirs = Timelog::factory()->create();

        $this->patch(route('timelogs.void', $theirs), ['reason' => 'Duplicate scan'])->assertNotFound();
    }
}
