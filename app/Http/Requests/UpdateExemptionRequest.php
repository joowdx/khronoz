<?php

namespace App\Http\Requests;

use App\Enums\ExemptionType;
use App\Http\Requests\Concerns\ClearsDayWindow;
use App\Models\Exemption;
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

    /**
     * Two rules mirror CHECKs that would otherwise arrive as a 500.
     *
     * `until` is `after_or_equal:date`, not `after` — a one-day exemption is
     * `until = date`, which is the canonical spelling since decision 38 made
     * the column NOT NULL.
     *
     * And **a multi-day exemption cannot carry hours**
     * (`exemptions_span_is_whole_days`). A 10:00–14:00 window repeated across
     * 105 days is not something an order ever means, and a row saying it would
     * make the deriver excuse four hours a day of a continuous statutory
     * leave — under-excusing by an entire entitlement. `prohibited_unless`
     * puts that on the field rather than in a 23514.
     *
     * On update the rule accepts **this row's own current employee**, even
     * when that employee has since been removed.
     *
     * `.ai/rules/requests.md` says an `exists` rule must accept only what the
     * picker offers, and on a store that is right. On an update it locks the
     * row: `RemoveEmployee` soft-deletes, the calendar row stays valid (the
     * paired FK never sees an UPDATE), the index null-guards the missing
     * person — and then correcting a remarks typo on a 105-day maternity
     * leave answered `employee_id: Not found`, because the only ids the rule
     * would take were living ones. Saving meant picking somebody else, which
     * moves the leave onto them.
     *
     * So: a living employee of this agency, **or** the id already on the row.
     * A new value is still held to the picker; the existing one is not
     * re-litigated.
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
