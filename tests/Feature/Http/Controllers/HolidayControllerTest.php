<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\HolidayType;
use App\Enums\Permission;
use App\Jobs\FanOutRecompute;
use App\Models\Agency;
use App\Models\Holiday;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HolidayControllerTest extends TestCase
{
    public function test_viewing_requires_calendar_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewTerminals);

        $this->get(route('holidays.index'))->assertForbidden();
    }

    public function test_the_index_shows_this_agencys_holidays_and_the_national_ones(): void
    {
        $agency = Agency::factory()->create();

        Holiday::factory()->national()->create(['name' => 'Bonifacio Day', 'date' => '2026-11-30']);
        $this->actingAsAgency($agency, Permission::ViewCalendar);
        Holiday::factory()->create(['agency_id' => $agency->id, 'name' => 'Charter Day', 'date' => '2026-11-30']);
        Holiday::factory()->create(['name' => 'Someone else', 'date' => '2026-11-30']);

        $this->get(route('holidays.index', ['year' => 2026]))->assertInertia(
            fn (Assert $page) => $page
                ->component('holidays/index')
                ->has('holidays', 2)
                ->where('holidays.0.name', 'Bonifacio Day')
                ->where('holidays.0.national', true)
                ->where('holidays.1.name', 'Charter Day')
                ->where('holidays.1.national', false)
        );
    }

    public function test_two_holidays_on_one_date_are_two_rows(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        Holiday::factory()->create(['agency_id' => $agency->id, 'name' => 'Bonifacio Day', 'date' => '2026-11-30']);

        $this->post(route('holidays.store'), [
            'date' => '2026-11-30',
            'name' => 'Eid al-Fitr',
            'type' => 'regular',
            'declared_at' => '2026-01-05',
        ])->assertSessionHasNoErrors();

        $this->get(route('holidays.index', ['year' => 2026]))
            ->assertInertia(fn (Assert $page) => $page->has('holidays', 2));
    }

    public function test_the_same_holiday_cannot_be_entered_twice_on_one_date(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        Holiday::factory()->create(['agency_id' => $agency->id, 'name' => 'Bonifacio Day', 'date' => '2026-11-30']);

        $this->post(route('holidays.store'), [
            'date' => '2026-11-30',
            'name' => 'Bonifacio Day',
            'type' => 'regular',
            'declared_at' => '2026-01-05',
        ])->assertSessionHasErrors('name');
    }

    public function test_an_agency_may_declare_a_local_holiday_on_a_national_one(): void
    {
        $agency = Agency::factory()->create();
        Holiday::factory()->national()->create(['name' => 'Bonifacio Day', 'date' => '2026-11-30']);
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('holidays.store'), [
            'date' => '2026-11-30',
            'name' => 'Bonifacio Day',
            'type' => 'local',
            'declared_at' => '2026-01-05',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Holiday::where('agency_id', $agency->id)->count());
    }

    public function test_an_agency_can_add_and_remove_its_own_holiday(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('holidays.store'), [
            'date' => '2026-08-19',
            'name' => 'Charter Day',
            'type' => 'local',
            'reference' => 'City Ordinance No. 12',
            'declared_at' => '2026-06-01',
        ])->assertSessionHas('success');

        $holiday = Holiday::where('agency_id', $agency->id)->sole();

        $this->delete(route('holidays.destroy', $holiday))->assertSessionHas('success');
        $this->assertDatabaseMissing('holidays', ['id' => $holiday->id]);
    }

    public function test_an_agency_cannot_edit_or_remove_a_national_holiday(): void
    {
        $agency = Agency::factory()->create();
        $national = Holiday::factory()->national()->create(['name' => 'Bonifacio Day', 'date' => '2026-11-30']);
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->get(route('holidays.edit', $national))->assertForbidden();
        $this->put(route('holidays.update', $national), [
            'date' => '2026-11-30',
            'name' => 'Tampered',
            'type' => 'working',
            'declared_at' => '2026-01-05',
        ])->assertForbidden();
        $this->delete(route('holidays.destroy', $national))->assertForbidden();

        $this->assertDatabaseHas('holidays', ['id' => $national->id, 'name' => 'Bonifacio Day']);
    }

    public function test_the_year_filter_narrows_the_list(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewCalendar);

        Holiday::factory()->create(['agency_id' => $agency->id, 'date' => '2025-11-30', 'name' => 'Old']);
        Holiday::factory()->create(['agency_id' => $agency->id, 'date' => '2026-11-30', 'name' => 'New']);

        $this->get(route('holidays.index', ['year' => 2025]))->assertInertia(
            fn (Assert $page) => $page->has('holidays', 1)->where('holidays.0.name', 'Old')
        );
    }

    public function test_a_mangled_year_falls_back_to_the_current_one(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewCalendar);

        $this->get(route('holidays.index', ['year' => 'nonsense']))->assertInertia(
            fn (Assert $page) => $page->where('filters.year', (string) today()->year)
        );
    }

    public function test_the_rate_is_labelled_by_the_enum(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewCalendar);
        Holiday::factory()->create(['agency_id' => $agency->id, 'date' => '2026-11-30', 'type' => HolidayType::Special]);

        $this->get(route('holidays.index', ['year' => 2026]))->assertInertia(
            fn (Assert $page) => $page
                ->where('holidays.0.type.value', 'special')
                ->where('holidays.0.type.label', 'Special non-working')
        );
    }

    public function test_the_form_offers_every_rate_the_check_allows(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageCalendar);

        $this->get(route('holidays.create'))->assertInertia(
            fn (Assert $page) => $page->where('rates', HolidayType::choices())
        );
    }

    public function test_declaring_a_holiday_queues_the_agency_over_its_iso_week(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        Queue::fake([FanOutRecompute::class]);

        $this->post(route('holidays.store'), [
            'date' => '2026-08-19',
            'name' => 'Charter Day',
            'type' => 'local',
            'declared_at' => '2026-06-01',
        ])->assertSessionHas('success');

        Queue::assertPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => $job->agencyId === $agency->id
                && $job->employeeIds === null
                && $job->from === '2026-08-17'
                && $job->to === '2026-08-23',
        );
    }

    public function test_moving_a_holiday_queues_both_weeks(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $holiday = Holiday::factory()->create([
            'agency_id' => $agency->id,
            'date' => '2026-08-19',
            'name' => 'Charter Day',
            'type' => HolidayType::Local,
        ]);

        Queue::fake([FanOutRecompute::class]);

        $this->put(route('holidays.update', $holiday), [
            'date' => '2026-08-26',
            'name' => 'Charter Day',
            'type' => 'local',
            'declared_at' => '2026-06-01',
        ])->assertSessionHas('success');

        Queue::assertPushedTimes(FanOutRecompute::class, 2);
        Queue::assertPushed(FanOutRecompute::class, fn (FanOutRecompute $job): bool => $job->from === '2026-08-17');
        Queue::assertPushed(FanOutRecompute::class, fn (FanOutRecompute $job): bool => $job->from === '2026-08-24');
    }

    public function test_renaming_a_holiday_queues_its_week_once(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $holiday = Holiday::factory()->create([
            'agency_id' => $agency->id,
            'date' => '2026-08-19',
            'name' => 'Charter Day',
            'type' => HolidayType::Local,
        ]);

        Queue::fake([FanOutRecompute::class]);

        $this->put(route('holidays.update', $holiday), [
            'date' => '2026-08-19',
            'name' => 'City Charter Day',
            'type' => 'local',
            'declared_at' => '2026-06-01',
        ])->assertSessionHas('success');

        Queue::assertPushedTimes(FanOutRecompute::class, 1);
    }

    public function test_removing_a_holiday_queues_its_week(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $holiday = Holiday::factory()->create([
            'agency_id' => $agency->id,
            'date' => '2026-08-19',
            'type' => HolidayType::Local,
        ]);

        Queue::fake([FanOutRecompute::class]);

        $this->delete(route('holidays.destroy', $holiday))->assertSessionHas('success');

        Queue::assertPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => $job->agencyId === $agency->id
                && $job->from === '2026-08-17'
                && $job->to === '2026-08-23',
        );
    }
}
