<?php

namespace App\Actions;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\Sync;
use App\Models\Terminal;
use App\Support\AttlogParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ImportTimelogs
{
    /**
     * @var array<string, array<string, true>>
     */
    private array $accepted = [];

    public function __construct(private readonly AttlogParser $parser) {}

    /**
     * @param  string  $reference  the source filename, for the audit trail
     * @return array{0: Sync, 1: list<array{employee_id: ?string, time: string}>}
     */
    public function handle(
        Terminal $terminal,
        string $path,
        string $reference,
        string $layout = AttlogParser::LAYOUT_STANDARD,
        int $size = 500,
    ): array {
        $this->accepted = [];

        $sync = Sync::create([
            'agency_id' => $terminal->agency_id,
            'terminal_id' => $terminal->id,
            'trigger' => SyncTrigger::Import,
            'status' => SyncStatus::Running,
            'started_at' => now(),
            'reference' => $reference,
        ]);

        $tally = ['accepted' => 0, 'duplicates' => 0, 'rejected' => 0];
        $earliest = null;
        $latest = null;
        $chunk = [];

        if ($refusal = $this->refuse($path, $layout, $terminal)) {
            $this->close($sync, SyncStatus::Failed, $tally, null, null, $refusal);

            return [$sync->refresh(), []];
        }

        try {
            foreach ($this->parser->parse($path, $layout) as [$row, $line]) {
                if ($row === null) {

                    $tally['rejected']++;

                    continue;
                }

                $chunk[] = $this->pending($sync, $terminal, $row);

                if (count($chunk) === $size) {
                    $this->flush($chunk, $tally, $earliest, $latest);
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $this->flush($chunk, $tally, $earliest, $latest);
            }

            $this->close($sync, SyncStatus::Completed, $tally, $earliest, $latest);
        } catch (Throwable $e) {
            $this->close($sync, SyncStatus::Failed, $tally, $earliest, $latest, $e->getMessage());

            throw $e;
        }

        return [$sync->refresh(), $this->pairs()];
    }

    private function refuse(string $path, string $layout, Terminal $terminal): ?string
    {

        $devices = [];

        foreach ($this->parser->parse($path, $layout) as [$row, $line]) {
            if ($row !== null && $row['device'] !== null && ! in_array($row['device'], $devices, true)) {
                $devices[] = $row['device'];
            }
        }

        if ($devices === []) {
            return null;
        }

        if (count($devices) > 1) {
            $named = implode(', ', $devices);

            return "The file names more than one device ({$named}). A scanner exports its own log, so this file was merged or altered; nothing was imported.";
        }

        $device = $devices[0];

        if ($device !== $terminal->code) {
            return "The file was recorded by device {$device}, but this terminal is device {$terminal->code}. Nothing was imported.";
        }

        return null;
    }

    /**
     * @param  array{uid: string, time: string, device: string|null, state: int, mode: int}  $row
     * @return array<string, mixed>
     */
    private function pending(Sync $sync, Terminal $terminal, array $row): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $terminal->agency_id,
            'terminal_id' => $terminal->id,
            'sync_id' => $sync->id,
            'uid' => $row['uid'],
            'time' => $row['time'],
            'state' => $row['state'],
            'mode' => $row['mode'],
            'source' => 'device',
            'created_at' => now(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     * @param  array{accepted: int, duplicates: int, rejected: int}  $tally
     * @param  ?string&  $earliest
     * @param  ?string&  $latest
     */
    private function flush(array $chunk, array &$tally, ?string &$earliest, ?string &$latest): void
    {
        $inserted = DB::table('timelogs')->insertOrIgnoreReturning(
            $chunk,
            ['id', 'employee_id', 'time'],
            ['terminal_id', 'uid', 'time', 'state', 'mode'],
        );

        $tally['accepted'] += $inserted->count();
        $tally['duplicates'] += count($chunk) - $inserted->count();

        foreach ($inserted as $row) {
            $earliest = $earliest === null ? $row->time : min($earliest, $row->time);
            $latest = $latest === null ? $row->time : max($latest, $row->time);
            $this->accepted[$row->employee_id ?? ''][CarbonImmutable::parse($row->time)->toDateString()] = true;
        }
    }

    /**
     * @return list<array{employee_id: ?string, time: string}>
     */
    private function pairs(): array
    {
        $pairs = [];

        foreach ($this->accepted as $employeeId => $dates) {
            foreach (array_keys($dates) as $date) {
                $pairs[] = [
                    'employee_id' => $employeeId === '' ? null : (string) $employeeId,
                    'time' => $date,
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param  array{accepted: int, duplicates: int, rejected: int}  $tally
     */
    private function close(
        Sync $sync,
        SyncStatus $status,
        array $tally,
        ?string $earliest,
        ?string $latest,
        ?string $error = null,
    ): void {
        $sync->update([
            'status' => $status,
            'finished_at' => now(),
            'received' => array_sum($tally),
            ...$tally,
            'earliest' => $earliest,
            'latest' => $latest,
            'error' => $error,
        ]);
    }
}
