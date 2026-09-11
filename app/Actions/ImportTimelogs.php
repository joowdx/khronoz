<?php

namespace App\Actions;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\Sync;
use App\Models\Terminal;
use App\Support\AttlogParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ingest a device's own attlog export into `timelogs`.
 *
 * The only ingestion path Milestone 5 ships (decision 40). It touches no
 * network and adds no dependency, which is the point: push and pull arrive
 * later against a core this has already proven.
 *
 * Four properties are answers to specific defects in the predecessor, and each
 * would look like an arbitrary choice without that context.
 *
 * **It takes its actor and its terminal as arguments.** The predecessor read
 * `Auth::user()` in a queued job's constructor and reported every outcome as a
 * Filament notification, so ingestion could not run outside an authenticated
 * web request. This returns the `Sync` and raises nothing; the same method
 * serves the Artisan command, the HTTP endpoint and a future queue job.
 *
 * **It does no post-ingest work.** No event, no recompute, no notification.
 * The predecessor ran its recompute synchronously after the raw-log
 * transaction committed, so a failure in the listener failed the job and
 * retried it as though ingestion itself had failed — while the timelogs were
 * already safely stored.
 *
 * **It never advances `stamp`, `synced_at` or `seen_at`** (decision 40). A
 * file is not an incremental device read; the export may be any month the
 * operator happened to have, and moving the read offset would make the first
 * real pull skip everything after it.
 *
 * **Chunks commit independently, deliberately.** A 400,000-row export must not
 * be one transaction. That is safe precisely because the insert is idempotent
 * on the attlog natural key — re-running an interrupted import inserts only
 * what is missing — and because the `syncs` row records where it stopped.
 */
final class ImportTimelogs
{
    public function __construct(private readonly AttlogParser $parser) {}

    /**
     * Stream $path into $terminal's timelogs and return the closed run.
     *
     * @param  string  $reference  the source filename, for the audit trail
     */
    public function handle(
        Terminal $terminal,
        string $path,
        string $reference,
        string $layout = AttlogParser::LAYOUT_STANDARD,
        int $size = 500,
    ): Sync {
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

            return $sync->refresh();
        }

        try {
            foreach ($this->parser->parse($path, $layout) as [$row, $line]) {
                if ($row === null) {
                    // A malformed line is counted and skipped, never thrown.
                    // The predecessor threw from inside its mapping closure,
                    // so one bad line at row 12,345 discarded 40,000 good
                    // punches — and left the earlier chunks committed anyway.
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

        return $sync->refresh();
    }

    /**
     * Why the run must not start, or null if it may.
     *
     * **A file names one device or it does not import.** A scanner exports its
     * own log, so more than one device number in one file means it was merged
     * or altered, and there is no flag to override that — the predecessor
     * refused such files too, with an error that said "likelihood of being
     * tampered with".
     *
     * Refusing the *file* rather than the offending rows is the whole point,
     * and rejecting row by row is strictly worse than doing nothing. Measured:
     * a genuine device-7 export with two forged device-3 rows appended, run
     * once against each terminal, imported every row including both forgeries
     * — each run reporting an unremarkable two accepted, two rejected. Per-row
     * rejection does not refuse a mixed file, it *splits* it, and two runs
     * reassemble it.
     *
     * The scan is a separate pass over the file, deliberately: "refuse" has to
     * mean nothing was written, and that is only true if the decision is made
     * before the first insert. It costs one sequential read of a local file and
     * holds a set of at most a handful of strings, so memory stays flat.
     *
     * Comparison is by string, per decision 42 — a code differing only in
     * padding is a terminal record to correct, not a comparison to loosen.
     */
    private function refuse(string $path, string $layout, Terminal $terminal): ?string
    {
        // A list compared with strict in_array, **not** a set keyed by the
        // device number. PHP silently casts a numeric-string array key to an
        // integer, so `$devices['7']` becomes `$devices[7]` and the code comes
        // back out as `int 7`, which `!== '7'`. Measured: that turned this very
        // check — the one decision 42 exists to enforce — into a refusal of
        // every correctly matched numeric device code.
        $devices = [];

        foreach ($this->parser->parse($path, $layout) as [$row, $line]) {
            if ($row !== null && $row['device'] !== null && ! in_array($row['device'], $devices, true)) {
                $devices[] = $row['device'];
            }
        }

        // No device column at all: LAYOUT_STANDARD files genuinely do not say
        // which scanner they came from, so the operator's choice stands.
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
     * One row as the database wants it.
     *
     * `employee_id` and `enrollment_id` are absent, not null-by-oversight: the
     * application may never write them and `timelogs_resolve` fills them on
     * insert (03-terminals.md rule 3). `id` and `created_at` are set by hand
     * because the query builder fires no model events.
     *
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
     * Insert a chunk and account for it.
     *
     * `insertOrIgnoreReturning` compiles to
     * `ON CONFLICT (attlog key) DO NOTHING RETURNING ...` and yields only the
     * rows actually inserted — so `accepted` is the count of what came back
     * and `duplicates` is the rest, arithmetic the predecessor could not do at
     * all. Its `upsert()` issued `DO UPDATE` against the same key, which
     * rewrote each row with itself, made "how many are new" unknowable, and
     * raised 21000 whenever one statement carried a key twice.
     *
     * No de-duplication pass runs here. `DO NOTHING` handles a repeated key
     * inside one statement, so memory stays flat — the predecessor's
     * `LazyCollection::unique()` buffered the whole file to dodge that.
     *
     * The span is taken over **accepted** rows only, and it is `min`/`max`
     * rather than first-and-last. Duplicates changed nothing, so they need no
     * recompute; and the predecessor's first-and-last span inverted itself on
     * any file not already in time order, which silently skipped the recompute
     * for every row it had just inserted.
     *
     * @param  list<array<string, mixed>>  $chunk
     * @param  array{accepted: int, duplicates: int, rejected: int}  $tally
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
        }
    }

    /**
     * Write the final counters in **one** UPDATE.
     *
     * `syncs_counts_balance` holds on every row at every moment, so the
     * counters cannot be incremented as the file streams — the first
     * `received = 1` with the rest at zero fires 23514. `received` is derived
     * from the other three rather than counted separately, which makes the
     * invariant impossible for this writer to break, including on the failure
     * path where a buffered chunk was never accounted for at all.
     *
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
