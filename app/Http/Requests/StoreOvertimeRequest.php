<?php

namespace App\Http\Requests;

use App\Enums\OvertimeMode;
use App\Models\Overtime;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Overtime::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => [
                'required', 'string',
                Rule::exists('employees', 'id')
                    ->where('agency_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'starts' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts'],
            'purpose' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::enum(OvertimeMode::class)],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'Not found',
            'ends.after' => 'Overtime that ends when it starts authorises nothing.',
        ];
    }

    /** @return array<string, mixed> */
    public function authorised(): array
    {
        $data = $this->validated();

        // `datetime-local` sends `Y-m-dTH:i`; the column wants a timestamp.
        $data['starts'] = str_replace('T', ' ', $data['starts']).':00';
        $data['ends'] = str_replace('T', ' ', $data['ends']).':00';

        return $data;
    }
}
