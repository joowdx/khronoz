<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Suspension;
use App\Models\Workgroup;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SuspensionControllerTest extends TestCase
{
    public function test_viewing_requires_calendar_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewTerminals);

        $this->get(route('suspensions.index'))->assertForbidden();
    }

    public function test_the_index_lists_this_agencys_suspensions_only(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewCalendar);

        Suspension::factory()->create(['agency_id' => $agency->id, 'date' => '2026-07-22', 'reason' => 'Typhoon']);
        Suspension::factory()->create(['reason' => 'Somebody else']);

        $this->get(route('suspensions.index', ['year' => 2026]))->assertInertia(
            fn (Assert $page) => $page
                ->component('suspensions/index')
                ->has('suspensions', 1)
                ->where('suspensions.0.reason', 'Typhoon')
        );
    }

    /** `workgroup_id` null is agency-wide, which is the commonest shape. */
    public function test_a_suspension_with_no_workgroup_covers_the_agency(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('suspensions.store'), [
            'date' => '2026-07-22',
            'reason' => 'Typhoon Signal No. 3',
            'declared_at' => '2026-07-22',
        ])->assertSessionHas('success');

        $suspension = Suspension::sole();

        $this->assertNull($suspension->workgroup_id);
        $this->assertNull($suspension->starts);
        $this->assertNull($suspension->ends);
    }

    /** Who declared it is the acting user, never a field the client sends (decision 39). */
    public function test_the_declaring_user_is_the_acting_user(): void
    {
        $agency = Agency::factory()->create();
        $user = $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('suspensions.store'), [
            'date' => '2026-07-22',
            'reason' => 'Brownout',
            'declared_at' => '2026-07-22',
        ])->assertSessionHas('success');

        $this->assertSame($user->id, Suspension::sole()->user_id);
    }

    /**
     * `suspensions_hours_paired` refuses a half-set window — "suspended from
     * noon until nothing" is not a declaration anyone can act on — so the
     * request mirrors it and the refusal lands on a field.
     */
    public function test_a_half_set_window_is_refused_on_the_field(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('suspensions.store'), [
            'date' => '2026-07-22',
            'reason' => 'Brownout',
            'declared_at' => '2026-07-22',
            'starts' => '12:00',
        ])->assertSessionHasErrors('ends');

        $this->assertSame(0, Suspension::count());
    }

    /** Strictly after, unlike every date range here: a zero-length window suspends nothing. */
    public function test_a_window_that_ends_when_it_starts_is_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('suspensions.store'), [
            'date' => '2026-07-22',
            'reason' => 'Brownout',
            'declared_at' => '2026-07-22',
            'starts' => '12:00',
            'ends' => '12:00',
        ])->assertSessionHasErrors('ends');
    }

    /**
     * A date may carry several, deliberately: a morning window and an
     * afternoon one are ordinary, and the schema has no key forbidding it.
     */
    public function test_one_date_may_carry_more_than_one_suspension(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        foreach ([['08:00', '12:00', 'Brownout, morning'], ['13:00', '17:00', 'Brownout, afternoon']] as [$from, $to, $why]) {
            $this->post(route('suspensions.store'), [
                'date' => '2026-07-22',
                'reason' => $why,
                'declared_at' => '2026-07-22',
                'starts' => $from,
                'ends' => $to,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Suspension::count());
    }

    public function test_a_workgroup_of_another_agency_is_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('suspensions.store'), [
            'date' => '2026-07-22',
            'reason' => 'Typhoon',
            'declared_at' => '2026-07-22',
            'workgroup_id' => Workgroup::factory()->create()->id,
        ])->assertSessionHasErrors('workgroup_id');
    }

    public function test_a_suspension_can_be_withdrawn(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $suspension = Suspension::factory()->create(['agency_id' => $agency->id]);

        $this->delete(route('suspensions.destroy', $suspension))->assertSessionHas('success');

        $this->assertDatabaseMissing('suspensions', ['id' => $suspension->id]);
    }

    public function test_another_agencys_suspension_is_not_reachable(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageCalendar);

        $this->get(route('suspensions.edit', Suspension::factory()->create()))->assertNotFound();
    }
}
