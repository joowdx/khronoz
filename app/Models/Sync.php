<?php

namespace App\Models;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\SyncFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ingestion run, and the durable answer to "what did that import
 * actually do" (docs/design/03-terminals.md).
 *
 * The predecessor had no such table: its counts rode in a fired event, one of
 * which had no listener and was dropped every time, and the figure it reported
 * was the size of the input rather than the number of rows inserted.
 *
 * The four counters are written **once**, in a single UPDATE that closes the
 * run — `syncs_counts_balance` refuses a partial tally, so incrementing them
 * as an import streams fires 23514 on the first row.
 *
 * `earliest` and `latest` are `min()`/`max()` over the stream, never the first
 * and last rows read. The predecessor used first-and-last and so produced an
 * inverted range on any export that was not already in time order, which
 * silently skipped the recompute for every row it had just inserted.
 *
 * `trigger` is a column name and also a Postgres keyword. It is non-reserved,
 * so it needs no quoting in a CHECK or a predicate — checked against the
 * parser rather than assumed.
 */
#[Fillable([
    'agency_id', 'terminal_id', 'trigger', 'status', 'started_at', 'finished_at',
    'drift', 'received', 'accepted', 'duplicates', 'rejected', 'reference',
    'earliest', 'latest', 'error',
])]
class Sync extends Model
{
    /** @use HasFactory<SyncFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger' => SyncTrigger::class,
            'status' => SyncStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'earliest' => 'datetime',
            'latest' => 'datetime',
            'drift' => 'integer',
            'received' => 'integer',
            'accepted' => 'integer',
            'duplicates' => 'integer',
            'rejected' => 'integer',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** The rows this run inserted. Only `accepted` rows are here; duplicates were skipped. */
    public function timelogs(): HasMany
    {
        return $this->hasMany(Timelog::class);
    }
}
