<?php

namespace Database\Factories;

use App\Actions\RecordAcceptance;
use App\Enums\Permission;
use App\Enums\Preset;
use App\Models\Agency;
use App\Models\User;
use App\Support\Legal;
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
            'pending_email' => null,
            'pending_email_token' => null,
            'pending_email_expires_at' => null,
            // Resources read this attribute, so factory defaults set it explicitly.
            'invited_at' => null,
            'password' => static::$password ??= Hash::make('password'),
            'permissions' => [],
            'remember_token' => Str::random(10),
        ];
    }

    public function acceptedLegal(): static
    {
        return $this->afterCreating(function (User $user): void {
            $documents = [];

            foreach (app(Legal::class)->current() as $document) {
                $documents[$document['slug']] = [
                    'version' => $document['version'], 'hash' => $document['hash'], 'accepted' => true,
                ];
            }

            app(RecordAcceptance::class)->handle($user, $documents);
        });
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
