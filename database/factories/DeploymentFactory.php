<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    /**
     * employee_id and workgroup_id are callbacks, not Employee::factory() /
     * Workgroup::factory() directly: the paired FKs (employee_id, agency_id) and
     * (workgroup_id, agency_id) require the employee and workgroup to belong to the
     * same agency as this deployment, so each is created explicitly under
     * the agency_id resolved just above rather than getting its own random
     * agency. agency_id is declared first so both callbacks can read the
     * already-resolved value from $attributes.
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
            'workgroup_id' => fn (array $attributes) => Workgroup::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'parent_id' => null,
            'starts' => fake()->dateTimeBetween('-5 years', 'now'),
            'ends' => null,
        ];
    }

    /**
     * A reassignment nested inside $placement (decision 31): the person works
     * elsewhere while the plantilla item stays put.
     *
     * Takes the parent's agency, employee and range wholesale rather than
     * generating its own. The agency and employee are structural — the paired
     * FK (parent_id, employee_id) refuses any other employee, and
     * (employee_id, agency_id) any other agency — while the range default
     * matches the parent's exactly because deployments_nested requires
     * containment and `daterange @>` is inclusive, so equal ranges are the
     * widest legal default. Override starts/ends at the call site to test
     * containment itself.
     */
    public function under(Deployment $placement): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $placement->agency_id,
            'employee_id' => $placement->employee_id,
            'parent_id' => $placement->id,
            'starts' => $placement->starts,
            'ends' => $placement->ends,
        ]);
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends' => null,
        ]);
    }

    /**
     * Departed: ended on or after it started (deployments_dates_ordered) and
     * before today.
     *
     * The value is a **closure**, not computed in the state closure, and that
     * is not style. A state closure receives the attributes accumulated *so
     * far*, and `create([...])` is appended as the last state — so a state
     * reading `$attributes['starts']` sees the definition's random default
     * and not the caller's date. Closures left in the returned array are
     * resolved after every state has merged, which is the only place the
     * final `starts` is visible. Eagerly, `closed()->create(['starts' =>
     * <a later date>])` computed `ends` against the default and the row was
     * refused with 23514 by deployments_dates_ordered — a schema-shaped
     * failure with no schema cause. ExemptionFactory::spanning() carries the
     * same fix for the same reason.
     *
     * **Departed, not merely "carries an end date"**, and the difference is
     * load-bearing rather than descriptive: WorkgroupController::heads()
     * offers `whereHas('currentDeployment')`, which is `coveringToday()`, so
     * an `ends` of today or later leaves the person *current* and back in the
     * head picker that WorkgroupControllerTest's
     * test_the_head_picker_offers_only_this_agency_s_employees_who_are_still_employed
     * and ineligibleHead(…, 'departed') both expect them gone from. `ends` is
     * inclusive, so the ceiling is **yesterday**: at today, `ends >= today`
     * holds and those two tests fail at random.
     *
     * The ceiling is clamped with max() rather than being a bare 'yesterday'
     * because fake()->dateTimeBetween() throws when its start is after its
     * end — a caller's future `starts` would otherwise trade the 23514 this
     * closure fixes for an InvalidArgumentException. Nobody can have departed
     * from a placement that has not begun, so such a row closes the day it
     * opens.
     *
     * Both bounds are passed as `Y-m-d` strings: Faker tests `instanceof
     * \DateTime`, which CarbonImmutable — a DateTimeImmutable — fails.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends' => function (array $merged): string {
                $starts = CarbonImmutable::parse($merged['starts'])->startOfDay();

                return fake()->dateTimeBetween(
                    $starts->toDateString(),
                    $starts->max(CarbonImmutable::yesterday())->toDateString(),
                )->format('Y-m-d');
            },
        ]);
    }
}
