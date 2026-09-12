<?php

namespace App\Attendance;

use App\Http\Resources\WorkdayResource;
use App\Models\Attestation;
use App\Models\Ledger;
use App\Support\Settings;

final class LedgerSnapshot
{
    /** @return array{identity: array, calculation: array} */
    public function capture(Ledger $ledger): array
    {
        $employee = $ledger->employee()->withTrashed()->firstOrFail();
        $group = $employee->operativeDeployment($ledger->ends)?->workgroup;
        $view = $ledger->rangeView();
        $view->workdays->loadMissing(['punches', 'exemption']);
        $totals = get_object_vars($view);
        unset($totals['workdays']);
        $settings = new Settings($ledger->agency);

        return [
            'identity' => [
                'agency' => ['id' => $ledger->agency_id, 'name' => $ledger->agency->name, 'code' => $ledger->agency->code],
                'employee' => ['id' => $employee->id, 'name' => $employee->name, 'number' => $employee->number, 'position' => $employee->position],
                'workgroup' => $group === null ? null : ['id' => $group->id, 'name' => $group->name, 'code' => $group->code, 'kind' => $group->kind, 'parent_id' => $group->parent_id, 'head_id' => $group->head_id],
                'cadence' => $ledger->cadence === null ? ['id' => null, 'name' => 'Monthly', 'kind' => 'monthly', 'rules' => ['starts' => [1]], 'anchor' => null] : ['id' => $ledger->cadence->id, 'name' => $ledger->cadence->name, 'kind' => $ledger->cadence->kind->value, 'rules' => $ledger->cadence->rules, 'anchor' => $ledger->cadence->anchor?->toDateString()],
            ],
            'calculation' => [
                'workdays' => WorkdayResource::collection($view->workdays)->resolve(),
                'totals' => $totals,
                'settings' => ['night_from' => $settings->nightFrom(), 'overtime_after_weekly_minutes' => $settings->overtimeAfterWeekly(), 'occurrences' => $settings->occurrences(), 'suspension_charge' => $settings->suspensionCharge(), 'premium_hours' => $settings->premiumHours(), 'overtime_gates' => $settings->overtimeGates(), 'missing_side' => $settings->missingSide()->value],
            ],
        ];
    }

    public function forRendition(Ledger $ledger): array
    {
        return [
            'version' => 1,
            'ledger' => ['id' => $ledger->id, 'starts' => $ledger->starts->toDateString(), 'ends' => $ledger->ends->toDateString(), 'scope' => $ledger->scope->value, 'scope_label' => $ledger->scope->label(), 'revision' => $ledger->revision, 'locked_at' => $ledger->locked_at->toIso8601String()],
            ...$ledger->identity,
            ...$ledger->calculation,
            'policy' => $ledger->policy,
            'signers' => $ledger->signers,
            'attestations' => $ledger->attestations()->whereNull('withdrawn_at')->orderBy('sequence')->get()
                ->map(fn (Attestation $attestation): array => ['id' => $attestation->id, 'role' => $attestation->role, 'user_id' => $attestation->user_id, 'name' => $attestation->name, 'sequence' => $attestation->sequence, 'at' => $attestation->at->toIso8601String()])->all(),
        ];
    }
}
