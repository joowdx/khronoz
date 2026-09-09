<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
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
        $this->actingAs(User::factory()->permissions($permission)->create())->get(route('users.index'))->assertForbidden();
    }

    public static function permissionsWithoutUsersManage(): array
    {
        return collect(Permission::cases())->reject(fn (Permission $p) => $p === Permission::ManageUsers)
            ->mapWithKeys(fn (Permission $p) => [$p->value => [$p]])->all();
    }

    public function test_index_lists_only_the_current_agency(): void
    {
        $admin = User::factory()->permissions(Permission::ManageUsers)->create();
        User::factory()->forAgency($admin->agency)->count(2)->create();
        User::factory()->count(3)->create(); // other agencies

        $this->actingAs($admin)->get(route('users.index'))
            ->assertInertia(fn (Assert $page) => $page->component('users/index')->has('users', 3));
    }

    /**
     * The superuser path through index: Gate::before grants a platform user
     * every ability, so UserPolicy::viewAny never actually runs for them.
     * What stops a platform user who has entered X from seeing every
     * agency's users is that index reads through $tenant->agency()->users()
     * rather than a bare User::query() — this proves that holds for a
     * platform actor specifically, with a fixture in all three places
     * (X, Y, platform) so the assertion actually discriminates between
     * them rather than passing by coincidence.
     */
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
        $admin = User::factory()->permissions(Permission::ManageUsers)->create();
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

    /**
     * Every user-management route besides index (covered above by the full
     * permission matrix) and destroy (covered below by the self-removal
     * case). None of the other given tests exercise these with anyone other
     * than a ManageUsers holder, so without this, forgetting to authorize
     * create/store/edit/update/invite would go uncaught.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
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
        $outsider = User::factory()->forAgency($agency)->create();

        $url = $needsColleague ? route($routeName, $colleague) : route($routeName);

        $this->actingAs($outsider)->{$verb}($url)->assertForbidden();
    }

    public function test_store_invites_a_user_with_the_chosen_permissions(): void
    {
        Notification::fake([InviteNotification::class]);
        $admin = User::factory()->permissions(Permission::ManageUsers)->create();

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
        $this->actingAs(User::factory()->permissions(Permission::ManageUsers)->create())
            ->post(route('users.store'), ['name' => 'x', 'email' => 'x@x.test', 'permissions' => ['root']])
            ->assertSessionHasErrors('permissions.0');
    }

    /**
     * The users_email index (0001_01_01_000000_create_users_table) is on
     * lower(email), so a duplicate submitted in a different case than the
     * stored row must still be caught here, before it ever reaches Postgres
     * as an uncaught constraint violation.
     *
     * Also pins the message itself (StoreUserRequest::messages()): the
     * stock "has already been taken" wording would confirm to the submitter
     * that the address has an account in some other agency — a cross-tenant
     * existence oracle the unique index is global enough to create.
     */
    public function test_store_requires_a_unique_email_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'ana@x.test']);
        $admin = User::factory()->permissions(Permission::ManageUsers)->create();

        $this->actingAs($admin)->post(route('users.store'), ['name' => 'Dup', 'email' => 'ANA@X.TEST', 'permissions' => []])
            ->assertSessionHasErrors(['email' => 'This address already has an account — ask them to sign in.']);
    }

    /**
     * The superuser path through store: Gate::before also lets a platform
     * user create a colleague without holding users.manage.
     * InviteUser::handle() assigns agency_id from app(Tenant::class)->id(),
     * which SetTenant set from the agency the platform user entered — this
     * proves the new row lands in X, not in the platform agency the actor
     * itself belongs to.
     */
    public function test_platform_user_store_creates_the_invited_user_in_the_entered_agency(): void
    {
        Notification::fake([InviteNotification::class]);
        $agencyX = Agency::factory()->create();

        $this->actingAsPlatform($agencyX);

        $this->post(route('users.store'), ['name' => 'Ana Cruz', 'email' => 'ana@x.test', 'permissions' => []])
            ->assertRedirect(route('users.index'));

        $user = User::withoutGlobalScopes()->where('email', 'ana@x.test')->firstOrFail();
        $this->assertSame($agencyX->id, $user->agency_id);
    }

    /**
     * The middleware-order tripwire: User::resolveRouteBindingQuery's
     * agency_id filter is only observable when it must exclude a row, so a
     * cross-tenant lookup is the only shape that can detect SetTenant
     * running after SubstituteBindings — a same-tenant lookup would resolve
     * to the same row whether or not the filter is even applied.
     */
    public function test_editing_a_user_of_another_agency_is_not_found(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs(User::factory()->permissions(Permission::ManageUsers)->create())
            ->get(route('users.edit', $stranger))->assertNotFound();
    }

    /**
     * Proves a legitimate ManageUsers holder can reach a colleague's edit
     * page, so this catches a broken authorization check or an unregistered
     * UserPolicy wrongly denying them (200 flipping to 403). It does not
     * guard the SetTenant/SubstituteBindings ordering: an unfiltered
     * {user} binding still resolves this same-tenant colleague to the same
     * row, so that regression would slip past here too — see
     * test_editing_a_user_of_another_agency_is_not_found for the test that
     * actually catches it.
     */
    public function test_editing_a_colleague_renders(): void
    {
        $admin = User::factory()->permissions(Permission::ManageUsers)->create();
        $colleague = User::factory()->forAgency($admin->agency)->create();

        $this->actingAs($admin)->get(route('users.edit', $colleague))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('users/edit')->where('user.id', $colleague->id));
    }

    public function test_update_persists_changes_and_redirects(): void
    {
        $admin = User::factory()->permissions(Permission::ManageUsers)->create();
        $colleague = User::factory()->forAgency($admin->agency)->permissions(Permission::ViewScheduling)->create();

        $this->actingAs($admin)->put(route('users.update', $colleague), [
            'name' => 'Renamed Colleague',
            'permissions' => ['organization.manage'],
        ])->assertRedirect(route('users.index'))->assertSessionHas('success');

        $colleague->refresh();
        $this->assertSame('Renamed Colleague', $colleague->name);
        $this->assertEqualsCanonicalizing(['organization.manage'], $colleague->permissions->map->value->all());
    }

    /**
     * UpdateUserRequest::preventsSelfDemotion() guards this: UserPolicy has
     * no visibility into the submitted permissions, so it cannot be the one
     * to refuse a users.manage holder editing themselves out of their own
     * access. Mirrors test_admins_cannot_remove_themselves (self-delete),
     * but this path fails validation (422/session error) rather than
     * authorization (403), since it depends on the payload, not just who
     * the target is.
     */
    public function test_self_edit_cannot_drop_users_manage(): void
    {
        $admin = User::factory()->permissions(Permission::ManageUsers, Permission::ViewScheduling)->create();

        $this->actingAs($admin)->put(route('users.update', $admin), [
            'name' => $admin->name,
            'permissions' => ['scheduling.view'],
        ])->assertSessionHasErrors('permissions');

        $admin->refresh();
        $this->assertTrue($admin->allows(Permission::ManageUsers));
    }

    /** The self-demotion guard is specific to self-edit: a colleague may still be edited down to fewer permissions, including losing users.manage. */
    public function test_editing_a_colleague_can_remove_their_users_manage(): void
    {
        $admin = User::factory()->permissions(Permission::ManageUsers)->create();
        $colleague = User::factory()->forAgency($admin->agency)->permissions(Permission::ManageUsers, Permission::ViewScheduling)->create();

        $this->actingAs($admin)->put(route('users.update', $colleague), [
            'name' => $colleague->name,
            'permissions' => ['scheduling.view'],
        ])->assertRedirect(route('users.index'));

        $colleague->refresh();
        $this->assertFalse($colleague->allows(Permission::ManageUsers));
    }

    /** Same cross-tenant binding protection as the edit route, exercised through update instead. */
    public function test_updating_a_user_of_another_agency_is_not_found(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs(User::factory()->permissions(Permission::ManageUsers)->create())
            ->put(route('users.update', $stranger), ['name' => 'Someone Else', 'permissions' => []])
            ->assertNotFound();
    }

    /**
     * The superuser path through update: Gate::before grants a platform
     * user every ability, so resolveRouteBindingQuery's agency_id filter is
     * the only thing stopping a platform user who has entered X from
     * reaching Y's user through this route — UserPolicy::update never even
     * gets asked. Same shape as test_updating_a_user_of_another_agency_is_not_found,
     * exercised by a platform actor instead of an ordinary ManageUsers holder.
     */
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
        $admin = User::factory()->permissions(Permission::ManageUsers)->create();

        $this->actingAs($admin)->delete(route('users.destroy', $admin))->assertForbidden();
    }

    public function test_destroy_removes_a_colleague_and_redirects(): void
    {
        $admin = User::factory()->permissions(Permission::ManageUsers)->create();
        $colleague = User::factory()->forAgency($admin->agency)->create();

        $this->actingAs($admin)->delete(route('users.destroy', $colleague))
            ->assertRedirect(route('users.index'))->assertSessionHas('success');

        $this->assertModelMissing($colleague);
    }

    /**
     * Same cross-tenant binding protection as the edit route, exercised
     * through destroy instead. $stranger belongs to another agency, so
     * this is the binding's 404, not UserPolicy::delete's separate 403 for
     * removing oneself (see test_admins_cannot_remove_themselves).
     */
    public function test_destroying_a_user_of_another_agency_is_not_found(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs(User::factory()->permissions(Permission::ManageUsers)->create())
            ->delete(route('users.destroy', $stranger))->assertNotFound();
    }

    /**
     * Same superuser binding protection as
     * test_platform_user_updating_another_agencys_user_is_not_found,
     * exercised through destroy instead.
     */
    public function test_platform_user_destroying_another_agencys_user_is_not_found(): void
    {
        $agencyX = Agency::factory()->create();
        $stranger = User::factory()->create(); // agency Y

        $this->actingAsPlatform($agencyX);

        $this->delete(route('users.destroy', $stranger))->assertNotFound();
    }

    public function test_resend_invite_notifies_the_user_again(): void
    {
        Notification::fake([InviteNotification::class]);
        $admin = User::factory()->permissions(Permission::ManageUsers)->create();
        $invited = User::factory()->forAgency($admin->agency)->invited()->create();

        $this->actingAs($admin)->post(route('users.invite', $invited))
            ->assertRedirect()->assertSessionHas('success');

        Notification::assertSentTo($invited, InviteNotification::class);
    }

    public function test_resend_invite_refuses_an_already_accepted_invitation(): void
    {
        Notification::fake([InviteNotification::class]);
        $admin = User::factory()->permissions(Permission::ManageUsers)->create();
        $accepted = User::factory()->forAgency($admin->agency)->create(); // default state: already verified

        $this->actingAs($admin)->post(route('users.invite', $accepted))
            ->assertRedirect()->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    /** Same cross-tenant binding protection as the edit route, exercised through invite instead. */
    public function test_inviting_a_user_of_another_agency_is_not_found(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs(User::factory()->permissions(Permission::ManageUsers)->create())
            ->post(route('users.invite', $stranger))->assertNotFound();
    }
}
