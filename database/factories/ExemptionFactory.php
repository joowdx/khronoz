<?php

namespace Database\Factories;

use App\Enums\ExemptionType;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exemption>
 */
class ExemptionFactory extends Factory
{
    /**
     * Define the model's default state: a single whole day of leave.
     *
     * `until` equals `date` for that single day (decision 38), as a closure so
     * a caller's own `date` is honoured. `starts`/`ends` null is the whole of
     * it, which also keeps the default clear of
     * exemptions_span_is_whole_days, so spanning() and hours() can each be
     * applied without the other refusing.
     *
     * employee_id and user_id are callbacks for the reason RosterFactory's
     * parents are: the paired FK requires the employee to share this row's
     * agency, so it is created under the agency_id resolved just above.
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
            'date' => '2026-09-15',
            'until' => fn (array $merged): string => $merged['date'],
            'type' => ExemptionType::Leave,
            'starts' => null,
            'ends' => null,
            'reference' => 'Application No. '.fake()->numberBetween(100, 999),
            'remarks' => null,
            'user_id' => fn (array $attributes) => User::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'approved_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['date'])->subDays(7),
        ];
    }

    /**
     * A continuous run of whole days — RA 11210's 105 for a live birth being
     * the case decision 37 exists for. `until` is exclusive of nothing: it is
     * the last day, so a 105-day leave ends on `date + 104`.
     *
     * The value is a **closure**, not computed in the state closure, and that
     * is not style. A state closure receives the attributes accumulated *so
     * far*, and `create([...])` is appended as the last state — so a state
     * reading `$attributes['date']` sees the definition's default and not the
     * caller's date. Closures left in the returned array are resolved after
     * every state has merged, which is the only place the final `date` is
     * visible. Measured: computing it eagerly here made
     * `spanning(105)->create(['date' => '2026-09-01'])` produce a 119-day
     * range, and the test that caught it was asserting the day after the last.
     *
     * `spanning(1)` is legal and is the default shape, `until = date`.
     */
    public function spanning(int $days): static
    {
        return $this->state(fn (array $attributes): array => [
            'until' => fn (array $merged): string => CarbonImmutable::parse($merged['date'])
                ->addDays($days - 1)
                ->toDateString(),
        ]);
    }

    /** A window of one day rather than all of it — a two-hour pass slip. */
    public function hours(string $starts = '10:00:00', string $ends = '12:00:00'): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts' => $starts,
            'ends' => $ends,
        ]);
    }

    /** The one type that excuses nothing (decision 19). */
    public function personal(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ExemptionType::Personal,
        ]);
    }
}
