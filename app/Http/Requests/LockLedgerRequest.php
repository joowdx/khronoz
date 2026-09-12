<?php

namespace App\Http\Requests;

use App\Enums\Work;
use App\Models\Employee;
use App\Models\Ledger;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LockLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! is_string($this->input('employee_id')) || ! $this->filled('employee_id')) {
            return $this->user()->can('ledgers.manage') || $this->user()->employee_id !== null;
        }
        $employee = Employee::withTrashed()->findOrFail($this->input('employee_id'));

        return $this->user()->can('lock', new Ledger(['agency_id' => $employee->agency_id, 'employee_id' => $employee->id]));
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'ulid', Rule::exists('employees', 'id')->where('agency_id', app(Tenant::class)->id())],
            'starts' => ['required', 'date_format:Y-m-d'],
            'ends' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts', 'before_or_equal:today'],
            'scope' => ['required', Rule::enum(Work::class)],
            'cadence_id' => ['nullable', 'ulid', Rule::exists('cadences', 'id')->where('agency_id', app(Tenant::class)->id())],
        ];
    }
}
