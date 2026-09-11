<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\LedgerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee-month: the DTR page with a lock on it
 * (docs/design/06-attendance.md Ledger rules 1–3). Created by the first
 * workday computed in that month. Stores `locked_at` only — totals and the
 * monthly occurrence counts are derived from its workdays.
 *
 * `workdays()` and `attestations()` land with those tables. `view()` is a
 * later chunk.
 */
#[Fillable(['agency_id', 'employee_id', 'month', 'locked_at'])]
class Ledger extends Model
{
    /** @use HasFactory<LedgerFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'locked_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function locked(): bool
    {
        return $this->locked_at !== null;
    }
}
