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
     */
    public function handle(Employee $employee): void
    {
        DB::transaction(function () use ($employee): void {
            $today = today();
            $placement = $employee->currentDeployment()->first();

            if ($placement !== null) {
                if ($placement->starts->gt($today)) {
                    $placement->delete();
                } else {
                    $placement->update(['ends' => $today]);
                }
            }

            $employee->delete();
        });
    }
}
