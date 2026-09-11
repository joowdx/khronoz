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

                if (! $this->belongsToTerminal($row, $terminal)) {
                    // The file names a different scanner than the one it is
                    // being imported into. An attlog carries no ULID, so this
                    // column is the file's *only* statement of where its
                    // punches came from — ignoring it means an operator who
                    // picks the wrong terminal misattributes every punch in
                    // the file and is told nothing. Counted as rejected rather
                    // than thrown, so a multi-device export still imports the
                    // rows that do belong here; when the terminal is simply
                    // wrong, every row rejects and the counters say so loudly.
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
     * Does the file's own device number agree with the terminal being imported
     * into?
     *
     * Null means the file does not say — `LAYOUT_STANDARD` has no device
     * column — and there is nothing to check, so the operator's choice stands.
     * That is not laxity: the file genuinely carries no other indicator, which
     * is exactly why `LAYOUT_DEVICE` files must be checked when they do.
     *
     * Compared as strings, per decision 42. `007` and `7` are different device
     * numbers for the same reason they are different device *users*; a code
     * that disagrees only in padding is a terminal record to correct, not a
     * comparison to loosen.
     *
     * @param  array{uid: string, time: string, device: string|null, state: int, mode: int}  $row
     */
    private function belongsToTerminal(array $row, Terminal $terminal): bool
    {
        return $row['device'] === null || $row['device'] === $terminal->code;
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
