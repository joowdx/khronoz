<?php

namespace App\Models;

use App\Enums\TimelogSource;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\TimelogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the device recorded (docs/design/03-terminals.md). Never a punch — a
 * punch is one matched slot side of a workday and arrives in M6.
 *
 * Four properties of this class are load-bearing.
 *
 * **`const UPDATED_AT = null`.** Not a style choice: the app role's UPDATE is
 * revoked down to `(voided_at, reason)`, so an Eloquent write that also
 * touched `updated_at` would fail 42501. `void()` works only because the
 * column does not exist.
 *
 * **No `SoftDeletes`, no `Prunable`, no visibility scope.** A bad timelog is
 * voided, not removed, and a voided one stays visible. The predecessor had
 * four global scopes hiding rows by default, so its own recompute silently
 * excluded data it had just been handed.
 *
 * **`employee_id` and `enrollment_id` are written by the database.** The
 * `timelogs_resolve` trigger fills them on insert and overwrites whatever was
 * sent. In-memory the model will not know — `create()` does not re-select — so
 * anything reading a freshly created timelog's employee must call `fresh()`.
 *
 * **`state` and `mode` stay raw integers** (rule 6). They are not enums here
 * because an unknown value from unfamiliar firmware must survive unchanged;
 * interpreting them is the deriver's job in M6, and an enum that silently
 * dropped an unrecognised code would lose the punch.
 */
#[Fillable([
    'agency_id', 'terminal_id', 'sync_id', 'uid', 'time', 'state', 'mode',
    'source', 'user_id', 'voided_at', 'reason',
])]
class Timelog extends Model
{
    /** @use HasFactory<TimelogFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** There is no `updated_at` column, and that is deliberate — see the docblock. */
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'time' => 'datetime',
            'state' => 'integer',
            'mode' => 'integer',
            'source' => TimelogSource::class,
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** The run that brought this row in. Null for a manual entry. */
    public function sync(): BelongsTo
    {
        return $this->belongsTo(Sync::class);
    }

    /** Null while unresolved, which is a normal and visible state. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** Who entered it, manual rows only. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mark a record bad without removing it — the only correction this table
     * allows. `reason` is required by `timelogs_void_needs_reason`: an
     * unexplained void takes a punch out of the record with nothing to audit.
     */
    public function void(string $reason): bool
    {
        return $this->update(['voided_at' => now(), 'reason' => $reason]);
    }

    /** Rows still standing. A voided row stays readable; it is simply not counted. */
    #[Scope]
    protected function standing(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /** Rows the database could not attribute to anybody. Visible on purpose. */
    #[Scope]
    protected function unresolved(Builder $query): void
    {
        $query->whereNull('employee_id');
    }
}
