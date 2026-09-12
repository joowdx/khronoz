<?php

namespace App\Http\Requests;

use App\Enums\CadenceKind;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCadenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ManageAgency->value);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'preferred' => $this->input('preferred', false),
            'rules' => $this->input('rules', match ($this->input('kind')) {
                'weekly', 'fortnightly' => [],
                'semimonthly' => ['starts' => [1, 16]],
                default => ['starts' => [1]],
            }),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(CadenceKind::class)],
            'anchor' => ['nullable', 'required_if:kind,weekly,fortnightly', 'prohibited_unless:kind,weekly,fortnightly', 'date_format:Y-m-d'],
            'rules' => ['present', 'array:starts'],
            'rules.starts' => ['sometimes', 'prohibited_if:kind,weekly,fortnightly', 'array', 'list', Rule::when($this->input('kind') === 'semimonthly', ['size:2'], ['size:1'])],
            'rules.starts.*' => ['required', 'integer', 'between:1,28', 'distinct'],
            'preferred' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $starts = $this->input('rules.starts', []);
            if (in_array($this->input('kind'), ['monthly', 'semimonthly'], true) && $starts === []) {
                $validator->errors()->add('rules.starts', 'Choose the starting days.');
            } elseif (count($starts) === 2 && $starts[0] >= $starts[1]) {
                $validator->errors()->add('rules.starts', 'Use ascending starting days.');
            }
        }];
    }
}
