<?php

namespace Database\Factories;

use App\Enums\HolidayType;
use App\Models\Agency;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    /**
     * Define the model's default state: a regular holiday belonging to its own
     * agency, declared well in advance.
     *
     * `name` is unique() for the reason ShiftFactory's is — the constraint is
     * `UNIQUE (agency_id, date, name)`, but tests routinely put several
     * holidays under one agency and, being a calendar, often on one date, so
     * the generator must not collide within an agency either.
     *
     * `declared_at` is derived from `date` rather than randomised: it is
     * prospective (Res. 2600838 §2.5), so a declaration dated *after* the
     * holiday is a real and meaningfully different case, and a factory that
     * produced one at random would make unrelated tests depend on it. Sixty
     * days before is the ordinary shape — the year's proclamations issue in
     * the year before.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'date' => '2026-11-30',
            'name' => 'Holiday '.fake()->unique()->lexify('????'),
            'type' => HolidayType::Regular,
            'reference' => 'Proclamation No. '.fake()->numberBetween(100, 999),
            'declared_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['date'])->subDays(60),
        ];
    }

    /**
     * National: owned by the platform agency, so it applies to every tenant
     * through AgencyOrPlatformScope.
     *
     * Note the ordering trap this shares with every cross-tenant fixture —
     * BelongsToAgency refuses an explicit agency_id that disagrees with a set
     * tenant, so a national holiday must be created **before** withTenant().
     */
    public function national(): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => Agency::platform()->id,
        ]);
    }

    /** A declared holiday that keeps the shift: an ordinary day at ordinary rates. */
    public function working(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => HolidayType::Working,
        ]);
    }

    /**
     * Declared retroactively — the case `declared_at`'s prospectivity exists
     * for.
     *
     * The value is a **closure** for the reason DeploymentFactory::closed()
     * spells out: a state closure sees only the attributes accumulated before
     * it, and `create([...])` is appended as the last state, so a state
     * reading `$attributes['date']` gets the definition's default. Note that
     * the definition's own `declared_at` above is already a closure; this
     * state contradicted it.
     *
     * Nothing in the schema catches this one. `holidays` ties `declared_at`
     * to `date` with no constraint, so eagerly the state produced a *silently
     * wrong* row rather than a refusal: against an overridden `date` it
     * returned the default date plus two, i.e. a declaration sixty-odd days
     * *before* the holiday — the exact inverse of what the state names. That
     * is why its test asserts a value instead of a refusal.
     */
    public function declaredAfter(): static
    {
        return $this->state(fn (array $attributes): array => [
            'declared_at' => fn (array $merged): CarbonImmutable => CarbonImmutable::parse($merged['date'])->addDays(2),
        ]);
    }
}
