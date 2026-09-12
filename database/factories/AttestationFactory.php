<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Attestation;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attestation>
 */
class AttestationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'ledger_id' => fn (array $attributes) => Ledger::factory()->locked()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'role' => 'timekeeper',
            'user_id' => fn (array $attributes) => User::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'at' => '2026-09-11 12:00:00',
        ];
    }
}
