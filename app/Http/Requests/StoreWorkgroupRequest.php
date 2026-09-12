<?php

namespace App\Http\Requests;

use App\Models\Workgroup;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreWorkgroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Workgroup::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => Str::upper((string) $this->input('code'))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $agencyId = app(Tenant::class)->id();

        return [
            'parent_id' => ['nullable', 'string', Rule::exists('workgroups', 'id')->where('agency_id', $agencyId)],
            'kind' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', Rule::unique('workgroups', 'code')->where('agency_id', $agencyId)],
            'name' => ['required', 'string', 'max:255'],
            'head_id' => ['nullable', 'string', Rule::exists('employees', 'id')->where('agency_id', $agencyId)->whereNull('deleted_at')->where(fn ($query) => $query->whereExists(fn ($deployment) => $deployment
                ->selectRaw('1')->from('deployments')
                ->whereColumn('deployments.employee_id', 'employees.id')
                ->whereColumn('deployments.agency_id', 'employees.agency_id')
                ->whereNull('deployments.ends')))],
        ];
    }
}
