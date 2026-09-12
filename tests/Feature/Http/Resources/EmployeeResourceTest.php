<?php

namespace Tests\Feature\Http\Resources;

use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeResourceTest extends TestCase
{
    public function test_employee_does_not_store_expose_or_index_unnecessary_personal_fields(): void
    {
        $employee = Employee::factory()->create();

        $wire = json_decode(json_encode(EmployeeResource::make($employee)->resolve()), true);

        foreach (['sex', 'birthdate', 'email', 'mobile'] as $field) {
            $this->assertFalse(Schema::hasColumn('employees', $field));
            $this->assertArrayNotHasKey($field, $wire);
            $this->assertArrayNotHasKey($field, $employee->toSearchableArray());
        }

        $this->assertTrue(Schema::hasColumn('users', 'email'));
    }
}
