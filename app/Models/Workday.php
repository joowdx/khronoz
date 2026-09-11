<?php

namespace App\Models;

use App\Enums\Premium;
use App\Enums\WorkdayStatus;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\WorkdayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee-day: the DTR line, with the shift it was computed against
 * frozen into it (docs/design/06-attendance.md Workday rules 1–3).
 *
 * **`const UPDATED_AT = null`.** A workday is fully derived and rewritten
 * wholesale by each recompute, so "when was this row last written" and "when
 * was it last computed" are one fact. `computed_at` is that fact and is what
 * the recompute reads; a second column holding the same instant is the
 * mixed-concern defect docs/reference/clockwork-audit.md records.
 *
 * `month` is generated from `date` and is therefore **not fillable** —
 * Postgres refuses any value for a generated column — and it is absent from a
 * freshly inserted model's attributes, which under Model::shouldBeStrict()
 * throws MissingAttributeException rather than answering null. The factory
 * refreshes after creating for that reason; a hand-built row must `refresh()`
 * before reading it.
 *
 * The column `shift` is the json snapshot — `$workday->shift['slots']` — and
 * the whole reason the column exists (Workday rule 2). The relation to the
 * `shifts` row is `resolvedShift()`, because a relation named `shift()` would
 * make Eloquent's dynamic property resolve `$workday->shift` to the related
 * model instead of the cast attribute, silently. `shift_id` is provenance.
 */
#[Fillable([
    'agency_id', 'ledger_id', 'employee_id', 'date', 'shift_id', 'shift',
    'exemption_id', 'status', 'premium', 'worked', 'credited', 'tardy',
    'undertime', 'excess', 'night', 'night_excess', 'computed_at',
])]
class Workday extends Model
{
    /** @use HasFactory<WorkdayFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** There is no `updated_at` column, and that is deliberate — see the docblock. */
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'month' => 'date',
            'shift' => 'array',
            'status' => WorkdayStatus::class,
            'premium' => Premium::class,
            'worked' => 'integer',
            'credited' => 'integer',
            'tardy' => 'integer',
            'undertime' => 'integer',
            'excess' => 'integer',
            'night' => 'integer',
            'night_excess' => 'integer',
            'computed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The shift row this snapshot was resolved from. Not `shift()`: that name
     * would shadow the json column, which is the thing every reader wants.
     */
    public function resolvedShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function exemption(): BelongsTo
    {
        return $this->belongsTo(Exemption::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(Punch::class);
    }
}
