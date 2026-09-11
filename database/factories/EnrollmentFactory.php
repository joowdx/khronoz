<?php

namespace Database\Factories;

use App\Enums\EnrollmentPrivilege;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Terminal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    /**
     * Define the model's default state: an ordinary user, currently enrolled.
     *
     * employee_id and terminal_id are callbacks for the reason
     * DeploymentFactory's parents are: both paired FKs require their parent to
     * share this row's agency, so each is created under the agency_id resolved
     * just above rather than getting its own random one.
     *
     * `uid` is a **string** and unique(), the latter because
     * enrollments_uid_one_person is scoped to (terminal_id, uid) and tests
     * routinely put several enrollments on one terminal. Zero-padded on
     * purpose: the padding is meaningless to an integer and meaningful here
     * (decision 42), so the default shape is the one that breaks a factory
     * anybody writes with a numeric uid.
     *
     * `starts` is a fixed date rather than a random one because both exclusion
     * constraints are about overlap, and a random start makes whether two
     * fixtures collide a coin toss.
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
            'terminal_id' => fn (array $attributes) => Terminal::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'uid' => fn () => str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'privilege' => EnrollmentPrivilege::User,
            'starts' => '2026-01-01',
            'ends' => null,
        ];
    }

    /**
     * Enrolled on an existing terminal, taking its agency wholesale.
     *
     * The agency is structural, not convenience: the paired FK refuses a
     * terminal of any other agency, so a test that creates the terminal first
     * has to hand it over rather than letting the definition invent one.
     */
    public function on(Terminal $terminal): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $terminal->agency_id,
            'terminal_id' => $terminal->id,
        ]);
    }

    /** Enrolled for $employee, whose agency the row must share. */
    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $employee->agency_id,
            'employee_id' => $employee->id,
        ]);
    }

    /**
     * Ended — the person left, or the device was reset and the uid reissued.
     *
     * A **closure**, not a value computed in the state closure, for the reason
     * DeploymentFactory::closed() spells out at length: a state closure sees
     * only the attributes accumulated before it, and `create([...])` merges
     * last, so a state reading `$attributes['starts']` gets the definition's
     * default rather than the caller's date. Here that would produce an `ends`
     * before `starts` and a 23514 from enrollments_dates_ordered — a
     * schema-shaped failure with no schema cause.
     */
    public function closed(string $after = '+1 year'): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends' => fn (array $merged): string => CarbonImmutable::parse($merged['starts'])
                ->modify($after)
                ->toDateString(),
        ]);
    }

    /** Can register other people on the device. */
    public function enroller(): static
    {
        return $this->state(fn (array $attributes): array => [
            'privilege' => EnrollmentPrivilege::Enroller,
        ]);
    }
}
