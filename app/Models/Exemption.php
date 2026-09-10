<?php

namespace App\Models;

use App\Enums\ExemptionType;
use App\Models\Concerns\BelongsToAgency;
use Carbon\CarbonInterface;
use Database\Factories\ExemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period an employee is excused from (docs/design/05-calendar.md rules 4
 * and 7).
 *
 * **`date` and `until` are both NOT NULL and both inclusive**, and a one-day
 * exemption carries `until = date` (decision 38). There is deliberately no
 * open-ended exemption: an authority always names its last day, so unlike
 * `deployments` and `rosters` this table has no null upper bound to interpret
 * — which is the point, since a null here would have meant the *opposite* of a
 * null there and `daterange(date, until, '[]')` would have read a two-hour
 * pass slip as excusing the rest of a career.
 *
 * Concerns\CoversDates is still not used, for a smaller reason now: its
 * predicate is written for a nullable `ends`, and this range needs no null
 * handling at all.
 *
 * A multi-day exemption is always whole days (exemptions_span_is_whole_days),
 * so the time window and the span are each other's negation rather than two
 * dimensions to combine.
 */
#[Fillable([
    'agency_id', 'employee_id', 'date', 'until', 'type',
    'starts', 'ends', 'reference', 'remarks', 'user_id', 'approved_at',
])]
class Exemption extends Model
{
    /** @use HasFactory<ExemptionFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'until' => 'date',
            'type' => ExemptionType::class,
            'approved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Who entered it — possibly a platform superuser who entered the agency. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this excuses the minutes it covers: not tardy, not undertime,
     * not absent. **The one place that decides** (decision 19,
     * 05-calendar.md rule 7); the deriver consults it and never the type
     * directly. False only for `personal`.
     */
    public function excused(): bool
    {
        return $this->type->excuses();
    }

    /** Whether the whole day is excused, rather than a window of it. */
    public function wholeDay(): bool
    {
        return $this->starts === null;
    }

    /** Whether this runs past its first day — a continuous statutory leave. */
    public function spansDays(): bool
    {
        return $this->until->gt($this->date);
    }

    /**
     * Exemptions covering $date. Closed on both sides, with no null to
     * handle — which is the whole benefit of decision 38 over its first
     * draft, where this needed a coalesce() that any future SQL would have
     * forgotten.
     */
    #[Scope]
    protected function covering(Builder $query, CarbonInterface $date): void
    {
        $query->where('date', '<=', $date->toDateString())
            ->where('until', '>=', $date->toDateString());
    }

    /** Exemptions overlapping $from..$to inclusive — what a month view asks for. */
    #[Scope]
    protected function overlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('date', '<=', $to->toDateString())
            ->where('until', '>=', $from->toDateString());
    }
}
