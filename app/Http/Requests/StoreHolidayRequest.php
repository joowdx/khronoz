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

    /**
     * The uniqueness rule carries `name`, and that is the whole point rather
     * than an oversight.
     *
     * **Coincident holidays must not collapse** (dole-rules.md I.6): Eid
     * al-Fitr landing on Bonifacio Day is two holidays on one date and both
     * are owed, at the higher rate. `holidays_agency_id_date_name_unique`
     * therefore keys on `(agency_id, date, name)` and not `(agency_id, date)`,
     * and this rule mirrors it exactly. A rule on date alone would refuse the
     * second and quietly cost somebody the more expensive premium.
     *
     * It is scoped to the tenant, so a national holiday on the same date does
     * not block an agency declaring a local one — which is the ordinary case
     * for a city charter day.
     *
     * @return array<string, mixed>
     */
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
