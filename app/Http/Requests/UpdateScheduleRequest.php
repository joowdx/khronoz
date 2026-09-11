<?php

namespace App\Http\Requests;

use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The store rules with the row's own name ignored.
 *
 * `.ai/rules/requests.md` asks an update's `exists` rule to accept the value
 * the row already holds as well as what the picker offers. Here the two are
 * the same set and nothing extra is needed: `shifts` does not soft-delete, and
 * a shift named by a schedule or a turn cannot be removed while it is
 * (`ON DELETE RESTRICT`), so `where('agency_id', …)` already answers for every
 * value this row can be carrying. Adding an `orWhere('id', …)` would mean
 * accepting a shift the picker cannot show.
 */
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

    /**
     * One shift per day of the cycle, no more and no fewer — and on an update
     * that is the half `turns_complete` would otherwise catch at COMMIT, since
     * shortening a cycle without dropping its tail leaves a complete set that
     * is complete for the wrong length.
     *
     * @return array<int, callable>
     */
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

    /**
     * The cycle's shifts in position order, reindexed from zero.
     *
     * @return array<int, string>
     */
    public function turns(): array
    {
        $turns = $this->validated()['turns'];

        ksort($turns);

        return array_values($turns);
    }
}
