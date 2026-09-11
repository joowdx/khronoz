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
    /**
     * Define the model's default state: a networked terminal serving its whole
     * agency, never yet read.
     *
     * Every column is set explicitly (.ai/rules/factories.md) — including the
     * ones nothing uses yet — because Model::shouldBeStrict() throws on any
     * attribute a factory never set.
     *
     * `code` is unique() because the constraint is `UNIQUE (agency_id, code)`
     * and tests routinely put several terminals under one agency; `serial` is
     * unique() because its index is **global**, so two terminals of different
     * agencies collide too.
     *
     * The default protocol is `File`, which is the one path M5 implements
     * (decision 40), so a bare `Terminal::factory()` produces a terminal the
     * importer can actually be pointed at. `host`, `port` and `secret` are
     * null to match: a file-import terminal has no network identity, and a
     * factory that invented one would make `terminals_protocol_valid`'s only
     * honest fixture the exception rather than the default.
     *
     * `stamp` is null — "read everything" — and `synced_at`/`seen_at` are null
     * for the same reason: a fresh terminal has been read zero times, and a
     * test asserting an import leaves them alone (decision 40) needs a known
     * starting value to assert against.
     *
     * @return array<string, mixed>
     */
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

    /**
     * Stationed at one office rather than serving the agency.
     *
     * Takes the workgroup rather than making one, because the paired FK
     * requires it to share this terminal's agency — the same reason
     * SuspensionFactory::forWorkgroup() does.
     */
    public function forWorkgroup(Workgroup $workgroup): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $workgroup->agency_id,
            'workgroup_id' => $workgroup->id,
        ]);
    }

    /**
     * Reachable over the network, with a comm key — the shape push and pull
     * will need. Nothing in M5 reads `host`, `port` or `secret`; this state
     * exists so the encrypted-at-rest test has a terminal whose secret is set.
     */
    public function networked(string $protocol = 'pull'): static
    {
        return $this->state(fn (array $attributes): array => [
            'protocol' => TerminalProtocol::from($protocol),
            'host' => fake()->localIpv4(),
            'port' => 4370,
            'secret' => 'comm-key-'.fake()->numerify('######'),
        ]);
    }

    /** An offline device: its export is carried across on a USB stick. */
    public function usb(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => TerminalKind::Usb,
            'protocol' => TerminalProtocol::File,
        ]);
    }

    /** Withdrawn from service. The punches it captured stay; only the switch flips. */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
