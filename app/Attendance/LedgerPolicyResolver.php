<?php

namespace App\Attendance;

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\User;
use App\Models\Workgroup;
use Carbon\CarbonInterface;

final class LedgerPolicyResolver
{
    /** @return array{template: string, roles: list<string>, supervisor: string, head_kind: ?string} */
    public function resolve(Employee $employee, CarbonInterface $date): array
    {
        $groups = $this->ancestry($employee->operativeDeployment($date)?->workgroup);
        $policies = Policy::query()->where('agency_id', $employee->agency_id)->get();
        $layers = [$policies->firstWhere('employee_id', $employee->id)];
        foreach ($groups as $group) {
            $layers[] = $policies->firstWhere('workgroup_id', $group->id);
        }
        $layers[] = $policies->first(fn (Policy $policy): bool => $policy->employee_id === null && $policy->workgroup_id === null);
        $resolved = ['template' => 'form48', 'roles' => ['employee', 'supervisor'], 'supervisor' => 'operative', 'head_kind' => null];
        foreach (array_keys($resolved) as $field) {
            foreach ($layers as $policy) {
                if ($policy?->{$field} !== null) {
                    $resolved[$field] = $policy->{$field};
                    break;
                }
            }
        }

        return $resolved;
    }

    /** @return list<array{role: string, user_ids: list<string>, users: list<array{id: string, name: string}>}> */
    public function signers(Employee $employee, CarbonInterface $date, array $policy): array
    {
        $operative = $employee->operativeDeployment($date)?->workgroup;
        $substantive = $employee->deployments()->whereNull('parent_id')->covering($date)->first()?->workgroup;
        $head = null;
        foreach ($this->ancestry($substantive) as $group) {
            if ($policy['head_kind'] !== null && $group->kind === $policy['head_kind']) {
                $head = $group->head_id;
                break;
            }
        }
        $signers = [];
        foreach ($policy['roles'] as $role) {
            $employeeId = match ($role) {
                'employee' => $employee->id,
                'supervisor' => ($policy['supervisor'] === 'substantive' ? $substantive : $operative)?->head_id,
                'head' => $head,
                default => null,
            };
            $users = User::query()->where('agency_id', $employee->agency_id)
                ->when($role !== 'timekeeper', fn ($query) => $query->where('employee_id', $employeeId)->whereNotNull('employee_id'))
                ->orderBy('id')->get();
            if ($role === 'timekeeper') {
                $users = $users->filter(fn (User $user): bool => $user->allows(Permission::AttestLedgers));
            }
            $signers[] = ['role' => $role, 'user_ids' => $users->values()->modelKeys(), 'users' => $users->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all()];
        }

        return $signers;
    }

    /** @return list<Workgroup> */
    private function ancestry(?Workgroup $group): array
    {
        $groups = [];
        while ($group !== null) {
            $groups[] = $group;
            $group = $group->parent;
        }

        return $groups;
    }
}
