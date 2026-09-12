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

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            // Strict models require this nullable attribute to be set explicitly.
            'employee_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Resources read this attribute, so factory defaults set it explicitly.
            'invited_at' => null,
            'password' => static::$password ??= Hash::make('password'),
            'permissions' => [],
            'remember_token' => Str::random(10),
        ];
    }

    public function platform(): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => Agency::platform()->id,
        ]);
    }

    public function forAgency(Agency $agency): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $agency->id,
        ]);
    }

    public function permissions(Permission ...$permissions): static
    {
        return $this->state(fn (array $attributes): array => [
            'permissions' => $permissions,
        ]);
    }

    public function preset(Preset $preset): static
    {
        return $this->state(fn (array $attributes): array => [
            'permissions' => $preset->permissions(),
        ]);
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'invited_at' => now(),
            'email_verified_at' => null,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }
}
/** @return static */
