<?php

namespace Database\Factories;

use App\Models\Acceptance;
use App\Models\User;
use App\Support\Legal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Acceptance> */
class AcceptanceFactory extends Factory
{
    public function definition(): array
    {
        $document = app(Legal::class)->document('privacy-policy');

        return [
            'user_id' => User::factory(),
            'agency_id' => fn (array $attributes) => User::findOrFail($attributes['user_id'])->agency_id,
            'document' => $document['slug'],
            'version' => $document['version'],
            'content_hash' => $document['hash'],
            'accepted_at' => now(),
        ];
    }
}
