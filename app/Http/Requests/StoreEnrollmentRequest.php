<?php

namespace App\Http\Requests;

use App\Enums\EnrollmentPrivilege;
use App\Models\Terminal;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('terminal'));
    }

    /**
     * `uid` is validated for shape and nothing else. Whether it collides is
     * the database's word — `enrollments_uid_one_person` and
     * `enrollments_one_uid_per_person` are exclusion constraints over a date
     * range, and a `Rule::unique` cannot express "not overlapping this range",
     * so a rule here would be a worse restatement that drifts. The controller
     * translates the 23P01 instead.
     *
     * What it *does* enforce is that the uid is not empty and not padded:
     * decision 42 makes it an opaque string, and `'  '` or `'7 '` would be
     * stored verbatim and then never match what the device reports.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $agency = app(Tenant::class)->id();

        return [
            'employee_id' => [
                'required', 'string',
                Rule::exists('employees', 'id')->where('agency_id', $agency)->whereNull('deleted_at'),
            ],
            'uid' => ['required', 'string', 'max:255', 'regex:/^\S+$/'],
            'privilege' => ['required', Rule::enum(EnrollmentPrivilege::class)],
            'starts' => ['required', 'date_format:Y-m-d'],
            'ends' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'Not found',
            'uid.regex' => 'The device user id cannot contain spaces — it must match exactly what the device reports.',
            'ends.after_or_equal' => 'An enrollment cannot end before it starts.',
        ];
    }

    public function terminal(): Terminal
    {
        return $this->route('terminal');
    }
}
