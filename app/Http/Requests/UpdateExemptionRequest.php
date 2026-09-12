<?php

namespace App\Http\Requests;

use App\Enums\ExemptionType;
use App\Http\Requests\Concerns\ClearsDayWindow;
use App\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExemptionRequest extends FormRequest
{
    use ClearsDayWindow;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('exemption'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => [
                'required', 'string',
                Rule::exists('employees', 'id')
                    ->where('agency_id', app(Tenant::class)->id())
                    ->where(fn (Builder $query) => $query
                        ->whereNull('deleted_at')
                        ->orWhere('id', $this->route('exemption')->employee_id)),
            ],
            'type' => ['required', Rule::enum(ExemptionType::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:date'],
            'partial' => $this->partialRules(),
            'starts' => ['nullable', 'required_with:ends', 'date_format:H:i', 'prohibited_unless:until,'.$this->input('date')],
            'ends' => ['nullable', 'required_with:starts', 'date_format:H:i', 'after:starts', 'prohibited_unless:until,'.$this->input('date')],
            'reference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'approved_at' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'Not found',
            'until.after_or_equal' => 'An exemption cannot end before it starts.',
            'starts.prohibited_unless' => 'Hours only apply to a single day. A leave spanning days excuses all of them.',
            'ends.prohibited_unless' => 'Hours only apply to a single day. A leave spanning days excuses all of them.',
            'ends.after' => 'The window must end after it starts.',
        ];
    }
}
