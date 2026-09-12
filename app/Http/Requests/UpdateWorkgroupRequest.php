<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateWorkgroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('workgroup'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => Str::upper((string) $this->input('code'))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $workgroup = $this->route('workgroup');

        return [
            'parent_id' => ['nullable', 'string', Rule::exists('workgroups', 'id')->where('agency_id', $workgroup->agency_id)],
            'kind' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', Rule::unique('workgroups', 'code')->where('agency_id', $workgroup->agency_id)->ignore($workgroup->id)],
            'name' => ['required', 'string', 'max:255'],
            'head_id' => ['nullable', 'string', Rule::exists('employees', 'id')->where('agency_id', $workgroup->agency_id)->whereNull('deleted_at')->where(fn ($query) => $query->whereExists(fn ($deployment) => $deployment
                ->selectRaw('1')->from('deployments')
                ->whereColumn('deployments.employee_id', 'employees.id')
                ->whereColumn('deployments.agency_id', 'employees.agency_id')
                ->whereNull('deployments.ends')))],
        ];
    }
}
