<?php

namespace Database\Factories;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\Agency;
use App\Models\Sync;
use App\Models\Terminal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sync>
 */
class SyncFactory extends Factory
{
    /**
     * Define the model's default state: an import that has opened and not yet
     * closed, which is the state every run passes through.
     *
     * All four counters are zero and every span column is null, and that is
     * the only shape a freshly opened run can legally take: `syncs_counts_
     * balance` is satisfied by 0 = 0 + 0 + 0, and `syncs_span_paired` by two
     * nulls. A factory that invented plausible-looking counts would have to
     * make them add up, and then every test asserting the importer's own
     * arithmetic would be asserting against numbers the factory chose.
     *
     * terminal_id is a callback for the reason every paired parent is: the FK
     * requires it to share this row's agency.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'terminal_id' => fn (array $attributes) => Terminal::factory()->create([
                'agency_id' => $attributes['agency_id'],
            ])->id,
            'trigger' => SyncTrigger::Import,
            'status' => SyncStatus::Running,
            'started_at' => '2026-09-01 08:00:00',
            'finished_at' => null,
            'drift' => null,
            'received' => 0,
            'accepted' => 0,
            'duplicates' => 0,
            'rejected' => 0,
            'reference' => 'attlog.dat',
            'earliest' => null,
            'latest' => null,
            'error' => null,
        ];
    }

    /** Reading from an existing terminal, whose agency the run must share. */
    public function on(Terminal $terminal): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $terminal->agency_id,
            'terminal_id' => $terminal->id,
        ]);
    }

    /**
     * Closed, with counts that balance — which the caller must keep balanced
     * if they override any of them, since `syncs_counts_balance` is the
     * database's word and not this factory's.
     *
     * `finished_at` is a closure for the reason DeploymentFactory::closed()
     * spells out: computed eagerly it would read the definition's default
     * `started_at` rather than a caller's, and produce a run that finished
     * before it began — a 23514 from `syncs_times_ordered` with no schema
     * cause.
     */
    public function completed(int $accepted = 8, int $duplicates = 2, int $rejected = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SyncStatus::Completed,
            'finished_at' => fn (array $merged): string => CarbonImmutable::parse($merged['started_at'])
                ->addMinutes(3)
                ->toDateTimeString(),
            'received' => $accepted + $duplicates + $rejected,
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'earliest' => '2026-09-01 07:58:00',
            'latest' => '2026-09-01 17:04:00',
        ]);
    }

    /** Could not finish. The counters stay at whatever had been tallied, which is nothing. */
    public function failed(string $error = 'The file could not be read.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SyncStatus::Failed,
            'finished_at' => fn (array $merged): string => CarbonImmutable::parse($merged['started_at'])
                ->addSeconds(4)
                ->toDateTimeString(),
            'error' => $error,
        ]);
    }
}
