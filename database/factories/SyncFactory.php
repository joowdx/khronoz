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
    /** @return array<string, mixed> */
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

    public function on(Terminal $terminal): static
    {
        return $this->state(fn (array $attributes): array => [
            'agency_id' => $terminal->agency_id,
            'terminal_id' => $terminal->id,
        ]);
    }

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
