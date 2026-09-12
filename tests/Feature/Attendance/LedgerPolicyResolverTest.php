<?php

namespace Tests\Feature\Attendance;

use App\Attendance\LedgerPolicyResolver;
use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\User;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class LedgerPolicyResolverTest extends TestCase
{
    public function test_resolves_each_field_independently_from_employee_through_ancestors(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $parent = Workgroup::factory()->for($agency)->create();
        $child = Workgroup::factory()->for($agency)->create(['parent_id' => $parent->id]);
        $employee = Employee::factory()->for($agency)->create();
        Deployment::factory()->for($agency)->for($employee)->for($child, 'workgroup')->create(['starts' => '2026-01-01', 'ends' => null]);
        Policy::factory()->for($agency)->create(['template' => 'plain', 'supervisor' => 'substantive']);
        Policy::factory()->for($agency)->create(['workgroup_id' => $parent->id, 'head_kind' => 'division']);
        Policy::factory()->for($agency)->create(['workgroup_id' => $child->id, 'roles' => ['employee', 'head']]);
        Policy::factory()->for($agency)->create(['employee_id' => $employee->id, 'template' => 'form48']);

        $policy = app(LedgerPolicyResolver::class)->resolve($employee, CarbonImmutable::parse('2026-08-31'));

        $this->assertSame(['template' => 'form48', 'roles' => ['employee', 'head'], 'supervisor' => 'substantive', 'head_kind' => 'division'], $policy);
    }

    public function test_signers_use_range_end_placement_and_same_agency_timekeepers(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $head = Employee::factory()->for($agency)->create();
        $group = Workgroup::factory()->for($agency)->create(['head_id' => $head->id]);
        $employee = Employee::factory()->for($agency)->create();
        $self = User::factory()->forAgency($agency)->create(['employee_id' => $employee->id]);
        $supervisor = User::factory()->forAgency($agency)->create(['employee_id' => $head->id]);
        $keeper = User::factory()->forAgency($agency)->permissions(Permission::AttestLedgers)->create();
        User::factory()->permissions(Permission::AttestLedgers)->create();
        Deployment::factory()->for($agency)->for($employee)->for($group, 'workgroup')->create(['starts' => '2026-01-01', 'ends' => '2026-09-01']);

        $signers = app(LedgerPolicyResolver::class)->signers($employee, CarbonImmutable::parse('2026-08-31'), ['roles' => ['employee', 'supervisor', 'timekeeper'], 'supervisor' => 'operative', 'head_kind' => null]);

        $this->assertSame([['role' => 'employee', 'user_ids' => [$self->id], 'users' => [['id' => $self->id, 'name' => $self->name]]], ['role' => 'supervisor', 'user_ids' => [$supervisor->id], 'users' => [['id' => $supervisor->id, 'name' => $supervisor->name]]], ['role' => 'timekeeper', 'user_ids' => [$keeper->id], 'users' => [['id' => $keeper->id, 'name' => $keeper->name]]]], $signers);
    }

    public function test_head_resolution_includes_the_substantive_group_itself(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $head = Employee::factory()->for($agency)->create();
        $group = Workgroup::factory()->for($agency)->create(['head_id' => $head->id, 'kind' => 'department']);
        $employee = Employee::factory()->for($agency)->create();
        $signer = User::factory()->forAgency($agency)->create(['employee_id' => $head->id]);
        Deployment::factory()->for($agency)->for($employee)->for($group, 'workgroup')->create(['starts' => '2026-01-01', 'ends' => null]);

        $signers = app(LedgerPolicyResolver::class)->signers($employee, CarbonImmutable::parse('2026-08-31'), ['roles' => ['head'], 'supervisor' => 'operative', 'head_kind' => 'department']);

        $this->assertSame([$signer->id], $signers[0]['user_ids']);
        $this->assertSame($signer->name, $signers[0]['users'][0]['name']);
    }
}
