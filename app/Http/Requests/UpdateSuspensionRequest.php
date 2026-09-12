<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ClearsDayWindow;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSuspensionRequest extends FormRequest
{
    use ClearsDayWindow;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('suspension'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'workgroup_id' => [
                'nullable', 'string',
                Rule::exists('workgroups', 'id')->where('agency_id', app(Tenant::class)->id()),
            ],
            'date' => ['required', 'date_format:Y-m-d'],
            'partial' => $this->partialRules(),
            'starts' => ['nullable', 'required_with:ends', 'date_format:H:i'],
            'ends' => ['nullable', 'required_with:starts', 'date_format:H:i', 'after:starts'],
            'reason' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'declared_at' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'workgroup_id.exists' => 'Not found',
            'starts.required_with' => 'Give both a start and an end, or leave both blank for the whole day.',
            'ends.required_with' => 'Give both a start and an end, or leave both blank for the whole day.',
            'ends.after' => 'A suspension that ends when it starts suspends nothing.',
        ];
    }
}
