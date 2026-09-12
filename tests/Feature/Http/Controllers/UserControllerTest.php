<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Enums\Preset;
use App\Models\Agency;
use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    #[DataProvider('permissionsWithoutUsersManage')]
    public function test_users_without_users_manage_are_forbidden(Permission $permission): void
    {
        $this->actingAs(User::factory()->acceptedLegal()->permissions($permission)->create())->get(route('users.index'))->assertForbidden();
    }

    public static function permissionsWithoutUsersManage(): array
    {
        return collect(Permission::cases())->reject(fn (Permission $p) => $p === Permission::ManageUsers)
            ->mapWithKeys(fn (Permission $p) => [$p->value => [$p]])->all();
    }

    public function test_index_lists_only_the_current_agency(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();
        User::factory()->forAgency($admin->agency)->count(2)->create();
        User::factory()->count(3)->create(); // other agencies

        $this->actingAs($admin)->get(route('users.index'))
            ->assertInertia(fn (Assert $page) => $page->component('users/index')->has('users', 3));
    }

    public function test_platform_user_in_an_entered_agency_sees_only_that_agencys_users(): void
    {
        $agencyX = Agency::factory()->create();
        $agencyY = Agency::factory()->create();
        $inX = User::factory()->forAgency($agencyX)->create();
        User::factory()->forAgency($agencyY)->create();
        User::factory()->platform()->create();

        $this->actingAsPlatform($agencyX);

        $this->get(route('users.index'))
            ->assertInertia(fn (Assert $page) => $page->component('users/index')
                ->has('users', 1)
                ->where('users.0.id', $inX->id));
    }

    public function test_index_filters_by_search_on_name_or_email(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create([
            'name' => 'Dolor Uy',
            'email' => 'dolor.uy@x.test',
        ]);
        // Matches by name; the email is deliberately unrelated to the search term.
        User::factory()->forAgency($admin->agency)->create(['name' => 'Ana Cruz', 'email' => 'zzz1@x.test']);
        // Matches by email only; the name is deliberately unrelated. Proves the
        // orWhereLike('email', ...) clause is genuinely wired, not just the name one.
        User::factory()->forAgency($admin->agency)->create(['name' => 'Ben Reyes', 'email' => 'ana-cruz@x.test']);
        // Matches neither.
        User::factory()->forAgency($admin->agency)->create(['name' => 'Cid Santos', 'email' => 'cid@x.test']);

        $this->actingAs($admin)->get(route('users.index', ['search' => 'ana']))
            ->assertInertia(fn (Assert $page) => $page->component('users/index')
                ->has('users', 2)
                ->where('filters.search', 'ana'));
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function userManagementRouteCases(): array
    {
        return [
            'create' => ['get', 'users.create', false],
            'store' => ['post', 'users.store', false],
            'edit' => ['get', 'users.edit', true],
            'update' => ['put', 'users.update', true],
            'destroy' => ['delete', 'users.destroy', true],
            'invite' => ['post', 'users.invite', true],
        ];
    }

    #[DataProvider('userManagementRouteCases')]
    public function test_non_manage_user_is_forbidden_from_every_user_management_route(string $verb, string $routeName, bool $needsColleague): void
    {
        $agency = Agency::factory()->create();
        $colleague = User::factory()->forAgency($agency)->create();
        $outsider = User::factory()->acceptedLegal()->forAgency($agency)->create();

        $url = $needsColleague ? route($routeName, $colleague) : route($routeName);

        $this->actingAs($outsider)->{$verb}($url)->assertForbidden();
    }

    public function test_store_invites_a_user_with_the_chosen_permissions(): void
    {
        Notification::fake([InviteNotification::class]);
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();

        $this->actingAs($admin)->post(route('users.store'), ['name' => 'Ana Cruz', 'email' => 'ana@x.test', 'permissions' => ['organization.manage', 'scheduling.view']])
            ->assertRedirect(route('users.index'));

        $user = User::withoutGlobalScopes()->where('email', 'ana@x.test')->firstOrFail();
        $this->assertSame($admin->agency_id, $user->agency_id);
        $this->assertNotNull($user->invited_at);
        $this->assertEqualsCanonicalizing(['organization.manage', 'scheduling.view'], $user->permissions->map->value->all());
        Notification::assertSentTo($user, InviteNotification::class);
    }

    public function test_store_rejects_unknown_permissions(): void
    {
        $this->actingAs(User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create())
            ->post(route('users.store'), ['name' => 'x', 'email' => 'x@x.test', 'permissions' => ['root']])
            ->assertSessionHasErrors('permissions.0');
    }

    public function test_store_requires_a_unique_email_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'ana@x.test']);
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();

        $this->actingAs($admin)->post(route('users.store'), ['name' => 'Dup', 'email' => 'ANA@X.TEST', 'permissions' => ['users.manage']])
            ->assertSessionHasErrors([
                'email' => 'Already taken',
                'email_conflict' => 'This address already has an account.',
            ]);
    }

    public function test_store_does_not_add_the_conflict_banner_to_a_malformed_address(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();

        $this->actingAs($admin)->post(route('users.store'), ['name' => 'Dup', 'email' => 'not-an-address', 'permissions' => ['users.manage']])
            ->assertSessionHasErrors(['email' => 'Enter a valid email'])
            ->assertSessionDoesntHaveErrors('email_conflict');
    }

    public function test_store_refuses_an_empty_permission_set(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();

        $this->actingAs($admin)->post(route('users.store'), ['name' => 'Ana Cruz', 'email' => 'ana@x.test', 'permissions' => []])
            ->assertSessionHasErrors(['permissions' => 'Choose at least one']);

        $this->assertDatabaseMissing('users', ['email' => 'ana@x.test']);
    }

    public function test_update_refuses_an_empty_permission_set(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();
        $colleague = User::factory()->forAgency($admin->agency)->permissions(Permission::ViewScheduling)->create();

        $this->actingAs($admin)->put(route('users.update', $colleague), ['name' => $colleague->name, 'permissions' => []])
            ->assertSessionHasErrors(['permissions' => 'Choose at least one']);

        $this->assertEqualsCanonicalizing(['scheduling.view'], $colleague->refresh()->permissions->map->value->all());
    }

    public function test_platform_user_store_creates_the_invited_user_in_the_entered_agency(): void
    {
        Notification::fake([InviteNotification::class]);
        $agencyX = Agency::factory()->create();

        $this->actingAsPlatform($agencyX);

        $this->post(route('users.store'), ['name' => 'Ana Cruz', 'email' => 'ana@x.test', 'permissions' => ['users.manage']])
            ->assertRedirect(route('users.index'));

        $user = User::withoutGlobalScopes()->where('email', 'ana@x.test')->firstOrFail();
        $this->assertSame($agencyX->id, $user->agency_id);
    }

    public function test_editing_a_user_of_another_agency_is_not_found(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs(User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create())
            ->get(route('users.edit', $stranger))->assertNotFound();
    }

    public function test_editing_a_colleague_renders(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();
        $colleague = User::factory()->forAgency($admin->agency)->create();

        $this->actingAs($admin)->get(route('users.edit', $colleague))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('users/edit')->where('user.id', $colleague->id));
    }

    public function test_update_persists_changes_and_redirects(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();
        $colleague = User::factory()->forAgency($admin->agency)->permissions(Permission::ViewScheduling)->create();

        $this->actingAs($admin)->put(route('users.update', $colleague), [
            'name' => 'Renamed Colleague',
            'permissions' => ['organization.manage'],
        ])->assertRedirect(route('users.index'))->assertSessionHas('success');

        $colleague->refresh();
        $this->assertSame('Renamed Colleague', $colleague->name);
        $this->assertEqualsCanonicalizing(['organization.manage'], $colleague->permissions->map->value->all());
    }

    public function test_self_edit_cannot_drop_users_manage(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers, Permission::ViewScheduling)->create();

        $this->actingAs($admin)->put(route('users.update', $admin), [
            'name' => $admin->name,
            'permissions' => ['scheduling.view'],
        ])->assertSessionHasErrors('permissions');

        $admin->refresh();
        $this->assertTrue($admin->allows(Permission::ManageUsers));
    }

    public function test_editing_a_colleague_can_remove_their_users_manage(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();
        $colleague = User::factory()->forAgency($admin->agency)->permissions(Permission::ManageUsers, Permission::ViewScheduling)->create();

        $this->actingAs($admin)->put(route('users.update', $colleague), [
            'name' => $colleague->name,
            'permissions' => ['scheduling.view'],
        ])->assertRedirect(route('users.index'));

        $colleague->refresh();
        $this->assertFalse($colleague->allows(Permission::ManageUsers));
    }

    public function test_updating_a_user_of_another_agency_is_not_found(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs(User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create())
            ->put(route('users.update', $stranger), ['name' => 'Someone Else', 'permissions' => []])
            ->assertNotFound();
    }

    public function test_platform_user_updating_another_agencys_user_is_not_found(): void
    {
        $agencyX = Agency::factory()->create();
        $stranger = User::factory()->create(); // agency Y

        $this->actingAsPlatform($agencyX);

        $this->put(route('users.update', $stranger), ['name' => 'Someone Else', 'permissions' => []])
            ->assertNotFound();
    }

    public function test_admins_cannot_remove_themselves(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();

        $this->actingAs($admin)->delete(route('users.destroy', $admin))->assertForbidden();
    }

    public function test_destroy_removes_a_colleague_and_redirects(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();
        $colleague = User::factory()->forAgency($admin->agency)->create();

        $this->actingAs($admin)->delete(route('users.destroy', $colleague))
            ->assertRedirect(route('users.index'))->assertSessionHas('success');

        $this->assertModelMissing($colleague);
    }

    public function test_destroying_a_user_of_another_agency_is_not_found(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs(User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create())
            ->delete(route('users.destroy', $stranger))->assertNotFound();
    }

    public function test_platform_user_destroying_another_agencys_user_is_not_found(): void
    {
        $agencyX = Agency::factory()->create();
        $stranger = User::factory()->create(); // agency Y

        $this->actingAsPlatform($agencyX);

        $this->delete(route('users.destroy', $stranger))->assertNotFound();
    }

    public function test_index_filters_by_status_invited(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();
        $invited = User::factory()->forAgency($admin->agency)->invited()->create();
        User::factory()->forAgency($admin->agency)->create(); // accepted

        $this->actingAs($admin)->get(route('users.index', ['status' => 'invited']))
            ->assertInertia(fn (Assert $page) => $page->component('users/index')
                ->has('users', 1)
                ->where('users.0.id', $invited->id)
                ->where('users.0.status', 'invited')
                ->where('filters.status', 'invited'));
    }

    public function test_index_filters_by_status_active(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();
        User::factory()->forAgency($admin->agency)->invited()->create();

        // The admin themselves is accepted, so two rows exist and one matches.
        $this->actingAs($admin)->get(route('users.index', ['status' => 'active']))
            ->assertInertia(fn (Assert $page) => $page->component('users/index')
                ->has('users', 1)
                ->where('users.0.id', $admin->id));
    }

    public function test_index_filters_by_access_preset_and_by_custom(): void
    {
        $agency = Agency::factory()->create();
        $admin = $this->actingAsAgency($agency, Permission::ManageUsers);
        $timekeeper = User::factory()->forAgency($agency)->preset(Preset::Timekeeper)->create();
        $nearlyTimekeeper = User::factory()->forAgency($agency)
            ->permissions(...Preset::Timekeeper->permissions(), ...[Permission::ManageAgency])->create();

        $this->get(route('users.index', ['access' => 'timekeeper']))
            ->assertInertia(fn (Assert $page) => $page->has('users', 1)
                ->where('users.0.id', $timekeeper->id)
                ->where('users.0.access.label', 'Timekeeper')
                ->where('users.0.access.preset', 'timekeeper'));

        // Custom is everything that matches no preset: the near-miss above
        // and the acting admin, who holds users.manage alone.
        $this->assertEqualsCanonicalizing(
            [$admin->id, $nearlyTimekeeper->id],
            $this->renderedIds(route('users.index', ['access' => 'custom'])),
        );
    }

    public function test_index_labels_access_by_preset_or_by_count(): void
    {
        $agency = Agency::factory()->create();
        // Named so the ascending name sort is deterministic: the acting user
        // is a row of this list too, and its faker name would land anywhere.
        $this->actingAs(User::factory()->acceptedLegal()->forAgency($agency)
            ->permissions(Permission::ManageUsers)->create(['name' => 'Zzz Actor']));
        User::factory()->forAgency($agency)->preset(Preset::Admin)->create(['name' => 'Aaa Admin']);
        User::factory()->forAgency($agency)
            ->permissions(Permission::ViewScheduling, Permission::ViewCalendar)->create(['name' => 'Bbb Two']);
        User::factory()->forAgency($agency)->permissions(Permission::ViewLedgers)->create(['name' => 'Ccc One']);
        User::factory()->forAgency($agency)->create(['name' => 'Ddd None']);

        $this->get(route('users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.0.access.label', 'Admin')
                ->where('users.1.access.label', '2 permissions')
                ->where('users.2.access.label', '1 permission')
                ->where('users.3.access.label', 'No permissions'));
    }

    public function test_index_sorts_by_access_and_by_status(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageUsers); // one permission
        $viewer = User::factory()->forAgency($agency)->preset(Preset::Viewer)->create();
        $none = User::factory()->forAgency($agency)->create(); // holds nothing

        $this->get(route('users.index', ['sort' => 'access', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.0.id', $viewer->id)
                ->where('users.2.id', $none->id)
                ->where('filters.sort', 'access')
                ->where('filters.direction', 'desc'));

        $invited = User::factory()->forAgency($agency)->invited()->create();

        $this->get(route('users.index', ['sort' => 'status', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page->where('users.0.id', $invited->id));
    }

    public function test_index_ignores_filters_it_does_not_recognise(): void
    {
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();

        $this->actingAs($admin)->get(route('users.index', [
            'sort' => 'permissions) --', 'direction' => 'desc; drop table users', 'status' => 'wat', 'access' => 'wat',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'asc')
            ->where('filters.status', 'any')
            ->where('filters.access', 'any')
            ->has('users', 1));
    }

    public function test_index_pages_the_list(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageUsers);
        User::factory()->forAgency($agency)->count(25)->create();

        $this->get(route('users.index'))
            ->assertInertia(fn (Assert $page) => $page->has('users', 25)
                ->where('pagination.from', 1)
                ->where('pagination.to', 25)
                ->where('pagination.total', 26)
                ->where('pagination.previous', null)
                ->whereNot('pagination.next', null));

        $this->get(route('users.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('users', 1)
                ->where('pagination.from', 26)
                ->where('pagination.next', null));
    }

    public function test_index_carries_an_invitation_link_only_while_it_is_outstanding(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageUsers);
        User::factory()->forAgency($agency)->invited()->create(['name' => 'Aaa Invited']);
        User::factory()->forAgency($agency)->create(['name' => 'Zzz Accepted']);

        $this->get(route('users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.0.status', 'invited')
                ->whereNot('users.0.invitation_url', null)
                ->where('users.2.status', 'active')
                ->where('users.2.invitation_url', null));
    }

    public function test_the_copied_invitation_link_opens_the_accept_screen(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageUsers);
        $invited = User::factory()->forAgency($agency)->invited()->create();

        $url = $this->renderedUsers(route('users.index', ['status' => 'invited']))[0]['invitation_url'];

        $this->assertIsString($url);
        $this->assertStringContainsString($invited->id, $url);

        // A signed URL is only accepted by a guest, so the acting session goes.
        auth()->logout();
        session()->flush();

        $this->get($url)->assertOk();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function renderedUsers(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props']['users'];
    }

    /**
     * @return array<int, string>
     */
    private function renderedIds(string $url): array
    {
        return array_column($this->renderedUsers($url), 'id');
    }

    public function test_create_carries_the_presets_the_matrix_starts_from(): void
    {
        $this->actingAs(User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create())
            ->get(route('users.create'))
            ->assertInertia(fn (Assert $page) => $page->component('users/create')
                ->has('presets', 3)
                ->where('presets.1.value', 'timekeeper')
                ->where('presets.1.label', 'Timekeeper')
                ->has('presets.1.permissions'));
    }

    public function test_a_stored_manage_right_grants_the_view_it_implies(): void
    {
        Notification::fake([InviteNotification::class]);
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Ana Cruz', 'email' => 'ana@x.test', 'permissions' => ['ledgers.attest'],
        ])->assertRedirect(route('users.index'));

        $user = User::withoutGlobalScopes()->where('email', 'ana@x.test')->firstOrFail();

        $this->assertSame(['ledgers.attest'], $user->permissions->map->value->all());
        $this->assertTrue($user->allows(Permission::ViewLedgers));
    }

    public function test_resend_invite_notifies_the_user_again(): void
    {
        Notification::fake([InviteNotification::class]);
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();
        $invited = User::factory()->forAgency($admin->agency)->invited()->create();

        $this->actingAs($admin)->post(route('users.invite', $invited))
            ->assertRedirect()->assertSessionHas('success');

        Notification::assertSentTo($invited, InviteNotification::class);
    }

    public function test_resend_invite_refuses_an_already_accepted_invitation(): void
    {
        Notification::fake([InviteNotification::class]);
        $admin = User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create();
        $accepted = User::factory()->forAgency($admin->agency)->create(); // default state: already verified

        $this->actingAs($admin)->post(route('users.invite', $accepted))
            ->assertRedirect()->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    public function test_inviting_a_user_of_another_agency_is_not_found(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs(User::factory()->acceptedLegal()->permissions(Permission::ManageUsers)->create())
            ->post(route('users.invite', $stranger))->assertNotFound();
    }
}
