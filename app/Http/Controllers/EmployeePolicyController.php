<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEmployeePolicyRequest;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Policy;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class EmployeePolicyController extends Controller
{
    public function update(UpdateEmployeePolicyRequest $request, Employee $employee, Tenant $tenant): RedirectResponse
    {
        DB::transaction(function () use ($request, $employee, $tenant): void {
            Agency::whereKey($tenant->id())->lockForUpdate()->firstOrFail();
            Policy::updateOrCreate(['employee_id' => $employee->id], $request->validated());
        });

        return redirect()->route('agency.settings.edit')->with('success', 'Policy updated.');
    }
}
