<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\AttestationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signature per role per ledger (docs/design/06-attendance.md Attestation
 * rules 1–6). Who may sign which role is Milestone 7.
 *
 * **`public $timestamps = false`.** `at` is when the signature was made, which
 * is the same instant the row was created, and two columns holding one fact
 * is the mixed-concern defect docs/reference/clockwork-audit.md records.
 * `updated_at` would be worse than redundant: REVOKE UPDATE means it can
 * never move, so it would be a column that permanently lies about being
 * maintained. Rule 6 — "timestamps and user ids only" — lists `at` alone.
 */
#[Fillable(['agency_id', 'ledger_id', 'role', 'user_id', 'at'])]
class Attestation extends Model
{
    /** @use HasFactory<AttestationFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** There are no timestamp columns; `at` is the instant — see the docblock. */
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'at' => 'datetime',
        ];
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
