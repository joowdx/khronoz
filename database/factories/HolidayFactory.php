<?php

namespace Database\Factories;

use App\Enums\HolidayType;
use App\Models\Agency;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'date' => '2026-11-30',
            'name' => 'Holiday '.fake()->unique()->lexify('????'),
            'type' => HolidayType::Regular,
            'reference' => 'Proclamation No. '.fake()->numberBetween(100, 999),
            'declared_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['date'])->subDays(60),
        ];
    }

    public function national(): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => Agency::platform()->id,
        ]);
    }

    public function working(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => HolidayType::Working,
        ]);
    }

    public function declaredAfter(): static
    {
        return $this->state(fn (array $attributes): array => [
            'declared_at' => fn (array $merged): CarbonImmutable => CarbonImmutable::parse($merged['date'])->addDays(2),
        ]);
    }
}
