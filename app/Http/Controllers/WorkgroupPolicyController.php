<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWorkgroupPolicyRequest;
use App\Models\Agency;
use App\Models\Policy;
use App\Models\Workgroup;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class WorkgroupPolicyController extends Controller
{
    public function update(UpdateWorkgroupPolicyRequest $request, Workgroup $workgroup, Tenant $tenant): RedirectResponse
    {
        DB::transaction(function () use ($request, $workgroup, $tenant): void {
            Agency::whereKey($tenant->id())->lockForUpdate()->firstOrFail();
            Policy::updateOrCreate(['workgroup_id' => $workgroup->id], $request->validated());
        });

        return redirect()->route('agency.settings.edit')->with('success', 'Policy updated.');
    }
}
