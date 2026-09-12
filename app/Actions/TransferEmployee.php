<?php

namespace App\Actions;

use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class TransferEmployee
{
    public function handle(Employee $employee, Workgroup $workgroup, CarbonInterface $starts, ?CarbonInterface $ends = null): Deployment
    {
        return DB::transaction(function () use ($employee, $workgroup, $starts, $ends): Deployment {
            $employee->currentDeployment?->update(['ends' => $starts->copy()->subDay()]);

            return $employee->deployments()->create([
                'agency_id' => $employee->agency_id,
                'workgroup_id' => $workgroup->id,
                'starts' => $starts,
                'ends' => $ends,
            ]);
        });
    }
}
