<?php

namespace App\Models;

use App\Enums\PunchKind;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\PunchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One expected slot side of a workday, and the timelog that filled it, or
 * null for a missed one (docs/design/06-attendance.md Punch).
 *
 * **`const UPDATED_AT = null`.** A punch is a derived row rewritten with its
 * workday, so "when was this last written" is the workday's `computed_at`.
 * The same mixed-concern defect a workday `updated_at` would be.
 */
#[Fillable([
    'agency_id', 'workday_id', 'employee_id', 'slot', 'kind',
    'expected_at', 'timelog_id', 'actual_at', 'deviation',
])]
class Punch extends Model
{
    /** @use HasFactory<PunchFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** There is no `updated_at` column, and that is deliberate — see the docblock. */
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => PunchKind::class,
            'slot' => 'integer',
            'expected_at' => 'datetime',
            'actual_at' => 'datetime',
            'deviation' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function workday(): BelongsTo
    {
        return $this->belongsTo(Workday::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function timelog(): BelongsTo
    {
        return $this->belongsTo(Timelog::class);
    }

    /** No timelog filled this slot side. */
    public function missed(): bool
    {
        return $this->timelog_id === null;
    }
}
