<?php

namespace App\Actions;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RemoveEmployee
{
    /**
     * Remove a personnel row, preserving placement history that actually
     * began. A future placement truncated to today would be empty: delete
     * it rather than inventing a day or violating deployments_dates_ordered.
     * Closing a started placement preserves its workgroup's delete restriction;
     * deleting a never-started placement releases that reference.
     *
     * Every row that has not finished is handled, not just one. Two things
     * made "the open placement" too narrow: a placement may be fixed-term, so
     * a row that is current today can already carry an `ends`; and a
     * reassignment is a second row covering the same day (decision 31). So
     * the set is every substantive placement and reassignment whose range has
     * not closed before today.
     *
     * **Reassignments before placements**, which the ordering enforces and
     * which is not cosmetic. A reassignment sits inside its parent's range,
     * so shrinking the parent first would strand it and deployments_nested
     * refuses that with P0001; and the paired FK refuses deleting a placement
     * that still has one nested under it, with 23001. Innermost first is the
     * only order that works, and `parent_id IS NULL` sorts false before true.
     *
     * The close is an expected-value UPDATE rather than a model save, because
     * these ranges are access control under decision 30: a read followed by
     * `$row->update()` is `WHERE id = ?`, which would overwrite an ending a
     * concurrent request had already recorded. Matching on the `ends` this
     * transaction actually read means a concurrent close wins instead of
     * being silently replaced.
     *
     * **A missed match aborts the removal**, and an adversarial review on
     * 2026-09-11 is why. Letting it pass looked safe on the open row — a CAS
     * on `ends IS NULL` can only miss because someone *closed* it, which is
     * the outcome anyway — but it is not safe on a fixed-term one: read
     * `ends = 2026-12-31`, have a concurrent correction move it to 2027, miss
     * the match, and the employee is soft-deleted while still holding a
     * placement that has not finished. Under decision 30 that leaves their
     * records visible to a workgroup indefinitely, which is the exact state
     * this action exists to prevent.
     *
     * The final re-check closes the other half of the same hole: a deployment
     * inserted by another request *after* the SELECT above would not be in
     * `$unfinished` at all, and READ COMMITTED gives this transaction no
     * reason to notice. Verifying that nothing unfinished remains, in the
     * same transaction and immediately before the soft delete, is what makes
     * the postcondition true rather than merely likely. Both failures roll
     * everything back, so the clerk retries against the state that surprised
     * them instead of half-removing someone.
     *
     * ABA is not a concern here, unlike in most compare-and-swap: the
     * predicate guards a *value*, not a writer's identity. If a concurrent
     * request closed the row and reopened it, the row is open again and
     * closing it on today is still exactly right.
     */
    public function handle(Employee $employee): void
    {
        DB::transaction(function () use ($employee): void {
            $today = today();

            $unfinished = $employee->deployments()
                ->where(fn ($query) => $query->whereNull('ends')->orWhere('ends', '>=', $today))
                ->orderByRaw('parent_id IS NULL')
                ->get();

            foreach ($unfinished as $row) {
                if ($row->starts->gt($today)) {
                    $row->delete();

                    continue;
                }

                $closed = $employee->deployments()
                    ->whereKey($row->id)
                    ->whereRaw('ends IS NOT DISTINCT FROM ?', [$row->getRawOriginal('ends')])
                    ->update(['ends' => $today]);

                if ($closed === 0) {
                    throw new RuntimeException('A placement changed while this employee was being removed.');
                }
            }

            // `> today`, not `>= today`: a row this action just closed ends
            // *on* today, which is finished, not unfinished. The selection
            // above uses `>=` because such a row is still worth visiting; the
            // postcondition must not count it as a failure.
            if ($employee->deployments()->where(fn ($query) => $query->whereNull('ends')->orWhere('ends', '>', $today))->exists()) {
                throw new RuntimeException('A placement was opened while this employee was being removed.');
            }

            $employee->delete();
        });
    }
}
