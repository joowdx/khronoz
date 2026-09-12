<?php

namespace Database\Factories;

use App\Models\Identity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Identity> */
class IdentityFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'agency_id' => fn (array $attributes) => User::findOrFail($attributes['user_id'])->agency_id,
            'provider' => 'google', 'subject' => fake()->uuid(), 'email' => fake()->safeEmail(), 'last_used_at' => null];
    }
}
