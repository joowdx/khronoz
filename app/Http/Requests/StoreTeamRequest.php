<?php

namespace App\Http\Requests;

use App\Models\Team;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Team::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $agency = app(Tenant::class)->id();

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('teams', 'name')->where('agency_id', $agency),
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
