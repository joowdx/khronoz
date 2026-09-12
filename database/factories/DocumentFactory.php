<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'name' => 'attendance.pdf',
            'mime' => 'application/pdf',
            'bytes' => 0,
            'algorithm' => 'sha256',
            'digest' => hash('sha256', ''),
        ];
    }
}
