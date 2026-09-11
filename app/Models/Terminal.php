<?php

namespace App\Models;

use App\Enums\TerminalKind;
use App\Enums\TerminalProtocol;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\TerminalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A biometric device that captures timelogs (docs/design/03-terminals.md).
 *
 * Named `Terminal` rather than `Device` by decision 16 — Passport owns
 * `devices` for OAuth device-authorization.
 *
 * Two properties of this model are load-bearing rather than stylistic.
 *
 * `secret` is **`encrypted`**, and it is cast that way before any writer
 * exists. The predecessor kept the same value in a plain varchar and then
 * leaked it through four more channels — a command-line flag, an event
 * payload, an unverified HTTPS POST, and the activity log. A cast added after
 * the first row is written is a data migration; added now it costs nothing.
 * Nothing in khronoz may put this value into an event, a job payload, a log
 * line, or argv (decision 40).
 *
 * `code` is a **string** and is never numeric-cast (decision 42), for the same
 * reason `Enrollment::uid` is: it is the device number the attlog carries, and
 * an integer cast merges `007` with `7` and raises on `A17`.
 *
 * `stamp` is the read offset for incremental pull and push (rule 5). A file
 * import **must not touch it** — see `Sync`.
 */
#[Fillable([
    'agency_id', 'workgroup_id', 'code', 'name', 'serial', 'kind', 'protocol',
    'host', 'port', 'secret', 'drift', 'meta', 'seen_at', 'synced_at', 'stamp', 'active',
])]
class Terminal extends Model
{
    /** @use HasFactory<TerminalFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TerminalKind::class,
            'protocol' => TerminalProtocol::class,
            'port' => 'integer',
            'secret' => 'encrypted',
            'drift' => 'integer',
            'meta' => 'array',
            'seen_at' => 'datetime',
            'synced_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /** Where the device sits; null means it serves the agency rather than one office. */
    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    /** Terminals still in service. `active` is a switch, not a delete — the punches stay. */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }
}
