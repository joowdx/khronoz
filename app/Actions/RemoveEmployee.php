<?php

namespace App\Actions;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RemoveEmployee
{
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

            if ($employee->deployments()->where(fn ($query) => $query->whereNull('ends')->orWhere('ends', '>', $today))->exists()) {
                throw new RuntimeException('A placement was opened while this employee was being removed.');
            }

            $employee->delete();
        });
    }
}
