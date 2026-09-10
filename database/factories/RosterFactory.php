<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Employee;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Roster>
 */
class RosterFactory extends Factory
{
    /**
     * Define the model's default state: an open-ended standing roster.
     *
     * employee_id and schedule_id are callbacks for the reason
     * DeploymentFactory's are: all three paired FKs require the parents to
     * share this roster's agency, so each parent is created under the
     * agency_id resolved just above rather than getting its own.
     *
     * team_id stays null — an ad-hoc assignment — because that is the shape
     * with no extra parent to build, and fromTeam() is the other one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'employee_id' => fn (array $attributes) => Employee::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'schedule_id' => fn (array $attributes) => Schedule::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'team_id' => null,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-07',
            'ends' => null,
        ];
    }

    /**
     * Issued from a cohort: the team's schedule and anchor are *copied*, not
     * referenced, which is what lets them diverge later without taking the
     * person off the team (07-constraints.md). Overriding either at the call
     * site is legal on purpose.
     */
    public function fromTeam(Team $team): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $team->agency_id,
            'team_id' => $team->id,
            'schedule_id' => $team->schedule_id,
            'anchor' => $team->anchor,
        ]);
    }

    /**
     * Ended on or after it started (rosters_dates_ordered), within a year of
     * starting.
     *
     * The value is a **closure**, not computed in the state closure, for the
     * reason DeploymentFactory::closed() and ExemptionFactory::spanning()
     * spell out: a state closure sees only the attributes accumulated before
     * it, and `create([...])` is appended as the last state, so a state
     * reading `$attributes['starts']` gets the definition's default rather
     * than the caller's date — which lands `ends` before `starts` and gets
     * the row refused with 23514 by rosters_dates_ordered.
     *
     * The window is measured from the **resolved `starts`** rather than from
     * now. The old ceiling was the literal '+1 year', which fake() resolves
     * against today and not against the anchor, so it only coincided with
     * "within a year of starting" because the default `starts` is itself
     * about today; a roster starting beyond next year was unsatisfiable
     * outright. Carbon arithmetic rather than fake()->dateTimeBetween()
     * keeps the anchor's type out of Faker's `instanceof \DateTime` check.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends' => fn (array $merged): string => CarbonImmutable::parse($merged['starts'])
                ->addDays(fake()->numberBetween(0, 365))
                ->toDateString(),
        ]);
    }
}
