<?php

namespace Database\Factories;

use App\Enums\RenditionStatus;
use App\Models\Agency;
use App\Models\Ledger;
use App\Models\Rendition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Rendition> */
class RenditionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'ledger_id' => fn (array $attributes) => Ledger::factory()->create(['agency_id' => $attributes['agency_id']])->id,
            'revision' => 1,
            'template' => 'form48',
            'snapshot' => (object) [],
            'token' => Str::random(48),
            'status' => RenditionStatus::Unstored,
            'document_id' => null,
            'requested_at' => null,
            'generated_at' => null,
            'failed_at' => null,
            'superseded_at' => null,
            'error' => null,
        ];
    }
}
