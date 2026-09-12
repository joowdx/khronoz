<?php

namespace Database\Factories;

use App\Enums\CadenceKind;
use App\Models\Agency;
use App\Models\Cadence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cadence> */
class CadenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'name' => fake()->unique()->words(3, true),
            'kind' => CadenceKind::Monthly,
            'rules' => ['starts' => [1]],
            'anchor' => null,
            'preferred' => false,
            'retired_at' => null,
        ];
    }
}
