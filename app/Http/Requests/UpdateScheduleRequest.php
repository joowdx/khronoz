<?php

namespace App\Http\Requests;

use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('schedule'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $schedule = $this->route('schedule');
        $agency = app(Tenant::class)->id();

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('schedules', 'name')->where('agency_id', $agency)->ignore($schedule->id),
            ],
            'length' => ['required', 'integer', 'between:1,366'],
            'fallback_shift_id' => [
                'nullable', 'string',
                Rule::exists('shifts', 'id')->where('agency_id', $agency),
            ],
            'turns' => ['required', 'array', 'max:366'],
            'turns.*' => [
                'required', 'string',
                Rule::exists('shifts', 'id')->where('agency_id', $agency),
            ],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['length', 'turns'])) {
                return;
            }

            if (count($this->array('turns')) !== $this->integer('length')) {
                $validator->errors()->add('turns', 'One shift for each day of the cycle.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fallback_shift_id.exists' => 'Not found',
            'turns.*.exists' => 'Not found',
        ];
    }

    /** @return array<int, string> */
    public function turns(): array
    {
        $turns = $this->validated()['turns'];

        ksort($turns);

        return array_values($turns);
    }
}
