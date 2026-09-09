<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('unit'));
    }

    /**
     * Upper-case the code before it is validated — see StoreUnitRequest for
     * why.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => Str::upper((string) $this->input('code'))]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * See StoreUnitRequest for why parent_id/head_id are explicitly scoped
     * by agency_id, and why a self-parent or a cycle is left to the database.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $unit = $this->route('unit');

        return [
            'parent_id' => ['nullable', 'string', Rule::exists('units', 'id')->where('agency_id', $unit->agency_id)],
            'kind' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', Rule::unique('units', 'code')->where('agency_id', $unit->agency_id)->ignore($unit->id)],
            'name' => ['required', 'string', 'max:255'],
            'head_id' => ['nullable', 'string', Rule::exists('employees', 'id')->where('agency_id', $unit->agency_id)],
        ];
    }
}
