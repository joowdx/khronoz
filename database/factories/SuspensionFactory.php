<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Suspension;
use App\Models\User;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Suspension>
 */
class SuspensionFactory extends Factory
{
    /**
     * Define the model's default state: an agency-wide, whole-day suspension —
     * a typhoon, which is both the commonest real shape and the one with no
     * extra parent to build.
     *
     * `workgroup_id` is therefore null, and forWorkgroup() is the other shape.
     * `starts`/`ends` are both null, held that way by
     * suspensions_hours_paired, and partial() sets both together for the same
     * reason.
     *
     * `user_id` is a callback rather than a plain User::factory(), for the
     * reason RosterFactory's parents are: the declaring user is created under
     * the agency_id resolved just above rather than getting its own agency.
     * Nothing pairs user_id against agency_id — a platform superuser may
     * declare for an agency they entered — but a factory that produced a
     * cross-agency user by default would make every test read as if that were
     * the ordinary case.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'workgroup_id' => null,
            'date' => '2026-09-15',
            'starts' => null,
            'ends' => null,
            'reason' => 'Typhoon '.fake()->firstName(),
            'reference' => 'Memorandum No. '.fake()->numberBetween(10, 99),
            'user_id' => fn (array $attributes) => User::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'declared_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['date'])->setTime(6, 0),
        ];
    }

    /**
     * Scoped to one workgroup and, by rule 3, its descendants. The agency is
     * taken from the workgroup: the paired FK requires them to match, so
     * setting one without the other is a 23503 the caller did not ask for.
     */
    public function forWorkgroup(Workgroup $workgroup): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $workgroup->agency_id,
            'workgroup_id' => $workgroup->id,
        ]);
    }

    /** A window of the day rather than all of it — work suspended from noon. */
    public function partial(string $starts = '12:00:00', string $ends = '17:00:00'): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts' => $starts,
            'ends' => $ends,
        ]);
    }
}
