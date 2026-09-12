<?php

namespace App\Http\Requests;

use App\Enums\HolidayType;
use App\Models\Holiday;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Holiday::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $agency = app(Tenant::class)->id();

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('holidays', 'name')
                    ->where('agency_id', $agency)
                    ->where('date', $this->input('date')),
            ],
            'type' => ['required', Rule::enum(HolidayType::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'declared_at' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'This agency already has a holiday by that name on that date.',
        ];
    }
}
