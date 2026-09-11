<?php

namespace App\Http\Requests;

use App\Enums\OvertimeMode;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('overtime'));
    }

    /**
     * No rule tries to express "does not overlap another authorisation for
     * this person" — `overtimes_no_overlap` is a gist exclusion over
     * `tsrange(starts, ends)` and no validation rule can say that. The
     * controller translates the 23P01 instead, which is the same division of
     * labour the enrollment screen makes.
     *
     * `date` is never accepted: it is a generated column (`starts::date`) and
     * Postgres refuses an insert into it outright.
     *
     * @return array<string, mixed>
     */
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
