<?php

namespace Tests\Feature\Http\Resources;

use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Tests\TestCase;

class EmployeeResourceTest extends TestCase
{
    /**
     * Important 3: birthdate/hired_at/separated_at are `date`-cast columns.
     * app.timezone is Asia/Manila (UTC+8), and an un-normalised date-cast
     * attribute JSON-serializes as a UTC instant — a stored 2020-01-01
     * crosses the wire as "2019-12-31T16:00:00.000000Z", the day before,
     * which every naive front-end date read (`.slice(0, 10)`,
     * `toLocaleDateString()` outside Manila) gets wrong. json_encode/decode
     * round-trips the resource the same way the real HTTP response does, so
     * this pins the literal wire value rather than the in-PHP Carbon object.
     */
    public function test_date_only_columns_cross_the_wire_as_plain_date_strings(): void
    {
        $employee = Employee::factory()->create([
            'birthdate' => '1990-05-05',
            'hired_at' => '2020-01-01',
            'separated_at' => '2023-12-31',
        ]);

        $wire = json_decode(json_encode(EmployeeResource::make($employee)->resolve()), true);

        $this->assertSame('1990-05-05', $wire['birthdate']);
        $this->assertSame('2020-01-01', $wire['hired_at']);
        $this->assertSame('2023-12-31', $wire['separated_at']);
    }
}
