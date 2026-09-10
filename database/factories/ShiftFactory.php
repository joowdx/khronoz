<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    /**
     * Define the model's default state: the standard 8–5 day of Rule XVII §5,
     * which is the shape most tests want and the one 04-scheduling.md leads
     * with.
     *
     * `name` is unique() for the same reason WorkgroupFactory's `code` is:
     * the constraint is only UNIQUE (agency_id, name), but callers routinely
     * make several shifts under one shared agency, so the generator must not
     * collide within one either.
     *
     * `color` is deliberately NOT unique(): the ramp has only eight steps
     * (rule 8), so unique() exhausts on the ninth shift of a run and throws
     * where nothing is actually wrong — there is no unique constraint on the
     * column. Picking the lowest index an agency is not using is the
     * application's job; the database only bounds the range.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'name' => 'Standard '.fake()->unique()->lexify('????'),
            'slots' => [
                ['in' => '08:00', 'out' => '12:00', 'window' => [-240, 180]],
                ['in' => '13:00', 'out' => '17:00', 'window' => [-120, 300]],
            ],
            'required' => 480,
            'flex' => 0,
            'remote' => false,
            'trust' => false,
            'color' => fake()->numberBetween(1, 8),
            'origin_id' => null,
        ];
    }

    /**
     * `Off`: no slots, and therefore no credit and no flex — the two CHECKs
     * shifts_off_credits_nothing and shifts_off_has_no_flex require both, so
     * they are set here rather than left to the caller to discover.
     */
    public function off(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Off '.fake()->unique()->lexify('????'),
            'slots' => [],
            'required' => 0,
            'flex' => 0,
            'remote' => false,
        ]);
    }

    /**
     * `Remote`: no slots either, but credited on attestation, so `required`
     * may stand (Flexiplace, OP MC 114). `remote` is what tells it from
     * `Off`, since both have empty slots.
     */
    public function remote(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Remote '.fake()->unique()->lexify('????'),
            'slots' => [],
            'flex' => 0,
            'remote' => true,
        ]);
    }

    /** Flexitime under MC 06 s. 2022: a three-hour arrival band. */
    public function flexible(int $minutes = 180): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Flexi '.fake()->unique()->lexify('????'),
            'slots' => [
                ['in' => '07:00', 'out' => '11:00', 'window' => [-30, 240]],
                ['in' => '12:00', 'out' => '16:00', 'window' => [-60, 360]],
            ],
            'flex' => $minutes,
        ]);
    }

    /** A copy of a platform-owned shift, which is what origin_id records. */
    public function copiedFrom(Shift $origin): static
    {
        return $this->state(fn (array $attributes): array => ['origin_id' => $origin->id]);
    }
}
