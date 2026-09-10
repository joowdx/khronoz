<?php

namespace App\Actions;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

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
     * being silently replaced, and zero affected rows is that outcome, not an
     * error — the row is closed either way, just not on this date.
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

                $employee->deployments()
                    ->whereKey($row->id)
                    ->whereRaw('ends IS NOT DISTINCT FROM ?', [$row->getRawOriginal('ends')])
                    ->update(['ends' => $today]);
            }

            $employee->delete();
        });
    }
}
