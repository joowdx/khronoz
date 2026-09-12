<?php

namespace Tests\Feature\Http\Resources;

use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Tests\TestCase;

class EmployeeResourceTest extends TestCase
{
    public function test_date_only_columns_cross_the_wire_as_plain_date_strings(): void
    {
        $employee = Employee::factory()->create([
            'birthdate' => '1990-05-05',
        ]);

        $wire = json_decode(json_encode(EmployeeResource::make($employee)->resolve()), true);

        $this->assertSame('1990-05-05', $wire['birthdate']);
    }
}
