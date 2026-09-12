<?php

namespace App\Actions;

use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReassignEmployee
{
    public function handle(Employee $employee, Workgroup $workgroup, CarbonInterface $starts, ?CarbonInterface $ends = null): Deployment
    {
        return DB::transaction(function () use ($employee, $workgroup, $starts, $ends): Deployment {
            $placement = $employee->currentDeployment()->first();

            if ($placement === null) {
                throw new RuntimeException('Cannot reassign an employee with no open placement.');
            }

            return $employee->deployments()->create([
                'agency_id' => $employee->agency_id,
                'workgroup_id' => $workgroup->id,
                'parent_id' => $placement->id,
                'starts' => $starts,
                'ends' => $ends,
            ]);
        });
    }
}
