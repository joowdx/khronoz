<?php

namespace Database\Factories;

use App\Enums\TerminalKind;
use App\Enums\TerminalProtocol;
use App\Models\Agency;
use App\Models\Terminal;
use App\Models\Workgroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Terminal>
 */
class TerminalFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'workgroup_id' => null,
            'code' => (string) fake()->unique()->numberBetween(1, 9999),
            'name' => 'Terminal '.fake()->unique()->lexify('????'),
            'serial' => strtoupper(fake()->unique()->bothify('??####????')),
            'kind' => TerminalKind::Terminal,
            'protocol' => TerminalProtocol::File,
            'host' => null,
            'port' => null,
            'secret' => null,
            'drift' => null,
            'meta' => null,
            'seen_at' => null,
            'synced_at' => null,
            'stamp' => null,
            'active' => true,
        ];
    }

    public function forWorkgroup(Workgroup $workgroup): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $workgroup->agency_id,
            'workgroup_id' => $workgroup->id,
        ]);
    }

    public function networked(string $protocol = 'pull'): static
    {
        return $this->state(fn (array $attributes): array => [
            'protocol' => TerminalProtocol::from($protocol),
            'host' => fake()->localIpv4(),
            'port' => 4370,
            'secret' => 'comm-key-'.fake()->numerify('######'),
        ]);
    }

    public function usb(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => TerminalKind::Usb,
            'protocol' => TerminalProtocol::File,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
