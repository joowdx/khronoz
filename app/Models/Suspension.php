<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Carbon\CarbonInterface;
use Database\Factories\SuspensionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Work suspended for a workgroup, or for the whole agency, on a date
 * (docs/design/05-calendar.md rules 2 and 3).
 *
 * `workgroup_id` null is agency-wide; a set one cascades to that workgroup's
 * descendants. Which employees that reaches is **not** "everyone deployed
 * under the workgroup" — it is everyone whose **operative** deployment on the
 * date sits in the subtree, the reassignment if one covers the date and
 * otherwise the substantive placement (decision 31). A closure declared on the
 * mother office must not excuse a day worked in the receiving one.
 *
 * Reads are plural, as with Holiday: a morning suspension and an afternoon one
 * on the same date are ordinary, and an agency-wide declaration may be
 * extended by a division for its own reason, so nothing here may `->first()`.
 *
 * `starts` and `ends` stay **strings** ('HH:MM:SS'), uncast. There is no
 * Eloquent `time` cast, and casting to a datetime would staple today's date
 * onto a clock time that belongs to `date` — which the deriver would then have
 * to strip off again. The whole schema already carries times as strings
 * (`shifts.slots`).
 */
#[Fillable(['agency_id', 'workgroup_id', 'date', 'starts', 'ends', 'reason', 'reference', 'user_id', 'declared_at'])]
class Suspension extends Model
{
    /** @use HasFactory<SuspensionFactory> */
    use BelongsToAgency, HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'declared_at' => 'datetime',
        ];
    }

    /** Null for an agency-wide suspension. */
    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }

    /** Who declared it — possibly a platform superuser who entered the agency. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether the whole day is suspended, rather than a window of it. */
    public function wholeDay(): bool
    {
        return $this->starts === null;
    }

    /** Every suspension declared for a date — plural, deliberately. */
    #[Scope]
    protected function covering(Builder $query, CarbonInterface $date): void
    {
        $query->whereDate('date', $date);
    }

    /** Every suspension from $from to $to inclusive — what a month view asks for. */
    #[Scope]
    protected function between(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }
}
