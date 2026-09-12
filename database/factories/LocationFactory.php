<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Document;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Location> */
class LocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'document_id' => fn (array $attributes) => Document::factory()->create(['agency_id' => $attributes['agency_id']])->id,
            'store' => 'local',
            'key' => 'documents/'.fake()->uuid().'.pdf',
            'verified_at' => null,
            'primary' => false,
            'retired_at' => null,
        ];
    }
}
