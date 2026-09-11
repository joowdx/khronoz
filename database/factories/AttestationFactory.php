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
    /**
     * Define the model's default state: a timekeeper signature on a locked
     * ledger of the same agency as the signer.
     *
     * The ledger is **locked** by default. `attestations_locked` refuses an
     * insert against an unlocked parent, so an ordinary `create()` would
     * otherwise trip the trigger rather than produce a row. A caller that
     * wants the refusal passes an unlocked `ledger_id`.
     *
     * The signer is created under this row's `agency_id`: the paired FK
     * `(user_id, agency_id)` refuses a user of any other agency, including a
     * platform superuser. That is the opposite of suspensions / exemptions /
     * overtimes, and it is the point.
     *
     * @return array<string, mixed>
     */
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
