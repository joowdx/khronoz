<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * For the two tables whose rows are date ranges an employee has at most one of
 * per date: `deployments` (per class, partitioned on `parent_id`) and
 * `rosters`.
 *
 * The scope is shared rather than written twice because getting it wrong in
 * one place is a silent correctness bug, not a visible one. **"Has not ended
 * yet" is not at most one** — `ends IS NULL OR ends >= today` is satisfied by
 * both a range ending in June and its successor starting in July — so a
 * `hasOne` built on it returns an arbitrary row. Covering a single date is at
 * most one, and only because an exclusion constraint forbids two rows of the
 * same class sharing a day.
 *
 * `ends` is inclusive throughout, since the ranges are built '[]', so a row
 * closed on a date still covers it and stops covering it the next day.
 */
trait CoversDates
{
    /** Rows whose range covers $date. */
    #[Scope]
    protected function covering(Builder $query, CarbonInterface $date): void
    {
        $query->where('starts', '<=', $date)
            ->where(fn (Builder $ended) => $ended->whereNull('ends')->orWhere('ends', '>=', $date));
    }

    /** `covering(today())`, the overwhelmingly common case. */
    #[Scope]
    protected function coveringToday(Builder $query): void
    {
        $query->covering(today());
    }
}
