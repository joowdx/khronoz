<?php

namespace Database\Factories;

use App\Enums\Permission;
use App\Enums\Preset;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * email_verified_at defaults to a non-null time: routes behind the
     * `verified` middleware (Tasks 6, 8, 9, 10) must render for a plain
     * User::factory()->create() without every caller opting in. invited()
     * is the only state that clears it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            // Explicitly null, not omitted: Model::shouldBeStrict() (AppServiceProvider)
            // throws MissingAttributeException on a column no attribute was ever set
            // for, and create() never re-selects the row afterwards to pick up the
            // database's own default. Every other real column below is set for the
            // same reason; the paired FK to employees itself arrives in Milestone 2.
            'employee_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Same reasoning as employee_id above: UserResource (Task 7) reads this
            // on every authenticated request via the shared `auth.user` prop, so any
            // plain User::factory()->create() needs it set, not omitted. invited()
            // is the only state that gives it a real value.
            'invited_at' => null,
            'password' => static::$password ??= Hash::make('password'),
            'permissions' => [],
            'remember_token' => Str::random(10),
        ];
    }

    /** A user of the platform agency: superuser, passes every gate (User::isPlatform()). */
    public function platform(): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => Agency::platform()->id,
        ]);
    }

    /** Belongs to a chosen agency instead of a fresh one. */
    public function forAgency(Agency $agency): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $agency->id,
        ]);
    }

    /** Holds exactly these permissions; grants() still resolves what each one implies. */
    public function permissions(Permission ...$permissions): static
    {
        return $this->state(fn (array $attributes): array => [
            'permissions' => $permissions,
        ]);
    }

    /** Holds a preset bundle's permissions. Presets are never themselves stored. */
    public function preset(Preset $preset): static
    {
        return $this->state(fn (array $attributes): array => [
            'permissions' => $preset->permissions(),
        ]);
    }

    /** Invited but has not yet signed in and set a password. */
    public function invited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'invited_at' => now(),
            'email_verified_at' => null,
        ]);
    }

    /** Has not verified their email address. */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }
}
