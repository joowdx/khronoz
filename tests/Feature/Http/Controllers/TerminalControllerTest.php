<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\StoreTerminalRequest;
use App\Models\Agency;
use App\Models\Enrollment;
use App\Models\Sync;
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

    public function test_removing_a_terminal_with_punches_is_refused_with_a_message(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = Terminal::factory()->create(['agency_id' => $agency->id]);
        Timelog::factory()->on($terminal)->create();

        $this->delete(route('terminals.destroy', $terminal))->assertSessionHas('error');

        $this->assertDatabaseHas('terminals', ['id' => $terminal->id]);
    }

    public function test_another_agencys_terminal_is_not_reachable(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $theirs = Terminal::factory()->create();

        $this->get(route('terminals.edit', $theirs))->assertNotFound();
    }

    public function test_a_duplicate_code_landing_after_validation_is_still_a_field_error(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        Terminal::factory()->create(['agency_id' => $agency->id, 'code' => '7']);

        $this->app->bind(StoreTerminalRequest::class, fn () => new class extends StoreTerminalRequest
        {
            /** @return array<string, mixed> */
            public function rules(): array
            {
                return array_merge(parent::rules(), ['code' => ['required', 'string']]);
            }
        });

        $this->post(route('terminals.store'), [
            'code' => '7',
            'name' => 'Annex',
            'kind' => 'terminal',
            'protocol' => 'file',
        ])->assertSessionHasErrors(['code' => 'Already taken']);

        $this->assertSame(1, Terminal::where('agency_id', $agency->id)->count());
    }

    public function test_removing_a_terminal_with_enrollments_says_so(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = Terminal::factory()->create(['agency_id' => $agency->id, 'name' => 'Lobby']);
        Enrollment::factory()->on($terminal)->create(['uid' => '0042']);

        $this->delete(route('terminals.destroy', $terminal));

        $this->assertSame(0, $terminal->timelogs()->count());
        $this->assertStringContainsString('enrollments', session('error'));
        $this->assertStringNotContainsString('timelogs', session('error'));
        $this->assertTrue(Terminal::whereKey($terminal->id)->exists());
    }

    public function test_the_index_offers_removal_only_when_nothing_points_at_the_terminal(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);

        $bare = Terminal::factory()->create(['agency_id' => $agency->id, 'code' => '1']);
        $enrolled = Terminal::factory()->create(['agency_id' => $agency->id, 'code' => '2']);
        Enrollment::factory()->on($enrolled)->create(['uid' => '0042', 'starts' => '2020-01-01', 'ends' => '2020-12-31']);
        $imported = Terminal::factory()->create(['agency_id' => $agency->id, 'code' => '3']);
        Sync::factory()->on($imported)->completed()->create();

        $this->get(route('terminals.index'))->assertInertia(
            fn (Assert $page) => $page
                ->where('terminals.0.enrollments_count', 0)
                ->where('terminals.0.syncs_count', 0)
                ->where('terminals.0.timelogs_count', 0)
                // The ended enrollment counts zero for *today* and one in all,
                // which is the distinction the gate was missing.
                ->where('terminals.1.enrolled_count', 0)
                ->where('terminals.1.enrollments_count', 1)
                ->where('terminals.2.syncs_count', 1)
        );
    }
}
