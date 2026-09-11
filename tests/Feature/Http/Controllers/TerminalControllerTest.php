<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Enrollment;
use App\Models\Terminal;
use App\Models\Timelog;
use App\Models\Workgroup;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TerminalControllerTest extends TestCase
{
    #[DataProvider('permissionsWithoutTerminalsView')]
    public function test_a_user_without_terminals_view_is_forbidden_from_the_index(Permission $permission): void
    {
        $this->actingAsAgency(Agency::factory()->create(), $permission);

        $this->get(route('terminals.index'))->assertForbidden();
    }

    /** @return array<string, array{0: Permission}> */
    public static function permissionsWithoutTerminalsView(): array
    {
        return collect(Permission::cases())
            ->reject(fn (Permission $p) => in_array($p, [Permission::ViewTerminals, Permission::ManageTerminals], true))
            ->mapWithKeys(fn (Permission $p) => [$p->value => [$p]])->all();
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function managementRoutes(): array
    {
        return [
            'create' => ['get', 'terminals.create', false],
            'store' => ['post', 'terminals.store', false],
            'edit' => ['get', 'terminals.edit', true],
            'update' => ['put', 'terminals.update', true],
            'destroy' => ['delete', 'terminals.destroy', true],
        ];
    }

    /**
     * `terminals.view` is not enough to change a terminal, and the split is not
     * bureaucratic: registering a device decides which files the importer will
     * accept and which device number they must carry (decision 44), so this is
     * the right to admit evidence rather than the right to edit a row.
     */
    #[DataProvider('managementRoutes')]
    public function test_view_permission_alone_cannot_reach_a_management_route(string $verb, string $route, bool $bound): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);

        $terminal = Terminal::factory()->create(['agency_id' => $agency->id]);

        $this->{$verb}($bound ? route($route, $terminal) : route($route))->assertForbidden();
    }

    public function test_the_index_lists_this_agencys_terminals_only(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);

        Terminal::factory()->create(['agency_id' => $agency->id, 'name' => 'Lobby']);
        Terminal::factory()->create(['name' => 'Someone else']);

        $this->get(route('terminals.index'))->assertInertia(
            fn (Assert $page) => $page
                ->component('terminals/index')
                ->has('terminals', 1)
                ->where('terminals.0.name', 'Lobby')
        );
    }

    /**
     * The two aggregates the list is built around.
     *
     * `enrolled_count` counts enrollments covering **today** — a closed one
     * does not mean the device can identify that person now — and
     * `timelogs_count` is every punch ever, because that is what decides
     * whether Remove can be offered at all.
     */
    public function test_the_index_counts_who_the_device_can_identify_today(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);

        $terminal = Terminal::factory()->create(['agency_id' => $agency->id]);
        Enrollment::factory()->on($terminal)->create(['starts' => today()->subMonth()->toDateString()]);
        // Ended before today, so it does not count: a closed enrollment does
        // not mean the device can identify that person now.
        Enrollment::factory()->on($terminal)->create([
            'starts' => today()->subYear()->toDateString(),
            'ends' => today()->subDay()->toDateString(),
        ]);
        Timelog::factory()->on($terminal)->count(3)->create();

        $this->get(route('terminals.index'))->assertInertia(
            fn (Assert $page) => $page
                ->where('terminals.0.enrolled_count', 1)
                ->where('terminals.0.timelogs_count', 3)
        );
    }

    /**
     * **The comm key never leaves the database** (decision 40). The
     * predecessor leaked the same value through five channels, one of which
     * was simply handing it to the client.
     */
    public function test_the_index_never_sends_the_comm_key(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);

        Terminal::factory()->networked()->create(['agency_id' => $agency->id, 'secret' => 'comm-key-424242']);

        $this->get(route('terminals.index'))->assertInertia(
            fn (Assert $page) => $page
                ->where('terminals.0.has_secret', true)
                ->missing('terminals.0.secret')
        )->assertDontSee('424242');
    }

    public function test_a_terminal_can_be_registered(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);

        $this->post(route('terminals.store'), [
            'code' => '7',
            'name' => 'Lobby entrance',
            'kind' => 'terminal',
            'protocol' => 'file',
        ])->assertRedirect(route('terminals.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('terminals', ['agency_id' => $agency->id, 'code' => '7', 'name' => 'Lobby entrance']);
    }

    /**
     * The device number is **not** normalised on the way in, unlike a
     * workgroup code. It is compared byte-for-byte against the `device` column
     * of every attlog line (decision 44), so `007` must survive as `007` — the
     * value typed here has to be the value the device emits.
     */
    public function test_a_device_number_is_stored_exactly_as_typed(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);

        $this->post(route('terminals.store'), [
            'code' => '007',
            'name' => 'Annex',
            'kind' => 'terminal',
            'protocol' => 'file',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('terminals', ['code' => '007']);
        $this->assertDatabaseMissing('terminals', ['code' => '7']);
    }

    /** Mirrors `UNIQUE (agency_id, code)`, so the refusal is a field error rather than a 500. */
    public function test_two_terminals_of_one_agency_cannot_share_a_device_number(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        Terminal::factory()->create(['agency_id' => $agency->id, 'code' => '1']);

        $this->post(route('terminals.store'), [
            'code' => '1',
            'name' => 'Another',
            'kind' => 'terminal',
            'protocol' => 'file',
        ])->assertSessionHasErrors('code');
    }

    /** A workgroup of another agency is refused by the picker's own rule, not by the paired FK. */
    public function test_a_terminal_cannot_be_stationed_in_another_agencys_workgroup(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $theirs = Workgroup::factory()->create();

        $this->post(route('terminals.store'), [
            'code' => '1',
            'name' => 'Lobby',
            'kind' => 'terminal',
            'protocol' => 'file',
            'workgroup_id' => $theirs->id,
        ])->assertSessionHasErrors('workgroup_id');
    }

    public function test_a_terminal_can_be_renumbered_without_touching_its_history(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);

        $terminal = Terminal::factory()->create(['agency_id' => $agency->id, 'code' => '1']);
        $punch = Timelog::factory()->on($terminal)->create(['uid' => '0042']);

        $this->put(route('terminals.update', $terminal), [
            'code' => '2',
            'name' => $terminal->name,
            'kind' => $terminal->kind->value,
            'protocol' => $terminal->protocol->value,
        ])->assertRedirect(route('terminals.index'));

        $this->assertSame('2', $terminal->fresh()->code);
        $this->assertSame('0042', $punch->fresh()->uid);
        $this->assertSame($terminal->id, $punch->fresh()->terminal_id);
    }

    public function test_a_terminal_that_has_captured_nothing_can_be_removed(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = Terminal::factory()->create(['agency_id' => $agency->id]);

        $this->delete(route('terminals.destroy', $terminal))
            ->assertRedirect(route('terminals.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('terminals', ['id' => $terminal->id]);
    }

    /**
     * The index hides Remove once a device has punches, but hiding an action
     * is not translating a refusal: this page can be stale while an import is
     * running, and the alternative to translating is a 500.
     */
    public function test_removing_a_terminal_with_punches_is_refused_with_a_message(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = Terminal::factory()->create(['agency_id' => $agency->id]);
        Timelog::factory()->on($terminal)->create();

        $this->delete(route('terminals.destroy', $terminal))->assertSessionHas('error');

        $this->assertDatabaseHas('terminals', ['id' => $terminal->id]);
    }

    /** Another agency's terminal is a 404, not a 403: the scope hides it entirely. */
    public function test_another_agencys_terminal_is_not_reachable(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $theirs = Terminal::factory()->create();

        $this->get(route('terminals.edit', $theirs))->assertNotFound();
    }
}
