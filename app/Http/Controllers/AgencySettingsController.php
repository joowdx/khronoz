<?php

namespace App\Http\Controllers;

use App\Enums\MissingSide;
use App\Enums\Permission;
use App\Http\Requests\UpdateAgencySettingsRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\PolicyResource;
use App\Http\Resources\WorkgroupResource;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\Workgroup;
use App\Support\Settings;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AgencySettingsController extends Controller
{
    public function edit(Tenant $tenant): Response
    {
        Gate::authorize(Permission::ManageAgency->value);
        $agency = $tenant->agency();
        $settings = new Settings($agency);
        $policy = Policy::whereNull('employee_id')->whereNull('workgroup_id')->first();

        return Inertia::render('agency/settings', [
            'settings' => [
                'ledger_archiving' => $agency->settings['ledger_archiving'] ?? false,
                'night_from' => $settings->nightFrom(),
                'overtime_after_weekly' => $settings->overtimeAfterWeekly() === null ? null : $settings->overtimeAfterWeekly() / 60,
                'occurrences' => $settings->occurrences(), 'suspension_charge' => $settings->suspensionCharge(),
                'premium_hours' => $settings->premiumHours(), 'overtime_gates' => $settings->overtimeGates(),
                'missing_side' => $settings->missingSide()->value,
            ],
            'policy' => $policy === null ? null : PolicyResource::make($policy)->resolve(),
            'policies' => PolicyResource::collection(Policy::where(fn ($query) => $query->whereNotNull('workgroup_id')->orWhereNotNull('employee_id'))->get())->resolve(),
            'workgroups' => WorkgroupResource::collection(Workgroup::orderBy('name')->get())->resolve(),
            'employees' => EmployeeResource::collection(Employee::orderBy('last_name')->orderBy('first_name')->get())->resolve(),
            'templates' => [
                ['value' => 'form48', 'label' => 'CSC Form 48'],
                ['value' => 'plain', 'label' => 'Plain'],
            ],
            'roles' => [
                ['value' => 'employee', 'label' => 'Employee'],
                ['value' => 'supervisor', 'label' => 'Supervisor'],
                ['value' => 'head', 'label' => 'Department head'],
                ['value' => 'timekeeper', 'label' => 'Timekeeper'],
            ],
            'supervisors' => [
                ['value' => 'operative', 'label' => 'Operative workgroup head'],
                ['value' => 'substantive', 'label' => 'Substantive workgroup head'],
            ],
            'missingSides' => array_map(
                fn (MissingSide $side): array => [
                    'value' => $side->value,
                    'label' => match ($side) {
                        MissingSide::Void => 'Do not credit an incomplete pair',
                        MissingSide::Assume => 'Credit the expected slot',
                    },
                ],
                MissingSide::cases(),
            ),
        ]);
    }

    public function update(UpdateAgencySettingsRequest $request, Tenant $tenant): RedirectResponse
    {
        DB::transaction(function () use ($request, $tenant): void {
            $agency = Agency::whereKey($tenant->id())->lockForUpdate()->firstOrFail();
            $settings = $request->validated('settings');
            foreach (['ledger_archiving', 'occurrences', 'suspension_charge', 'premium_hours', 'overtime_gates'] as $boolean) {
                if (array_key_exists($boolean, $settings)) {
                    $settings[$boolean] = $request->boolean('settings.'.$boolean);
                }
            }
            $agency->update(['settings' => [...($agency->settings ?? []), ...$settings]]);
            if ($request->has('policy')) {
                Policy::updateOrCreate(['employee_id' => null, 'workgroup_id' => null], $request->validated('policy'));
            }
        });

        return redirect()->route('agency.settings.edit')->with('success', 'Agency settings updated.');
    }
}
