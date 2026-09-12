<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Policy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Policy> */
class PolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'workgroup_id' => null,
            'employee_id' => null,
            'template' => null,
            'roles' => null,
            'supervisor' => null,
            'head_kind' => null,
        ];
    }
}
