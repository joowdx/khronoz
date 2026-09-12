<?php

namespace App\Http\Requests;

use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('team'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $team = $this->route('team');
        $agency = app(Tenant::class)->id();

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('teams', 'name')->where('agency_id', $agency)->ignore($team->id),
            ],
            'schedule_id' => [
                'required', 'string',
                Rule::exists('schedules', 'id')->where('agency_id', $agency),
            ],
            'anchor' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['schedule_id.exists' => 'Not found'];
    }
}
