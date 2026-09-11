<?php

namespace App\Http\Requests;

use App\Models\Schedule;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A cycle and the days in it, submitted together (04-scheduling.md).
 *
 * `turns` is an **ordered array of shift ids**, one per day: its index is the
 * turn's `position`, so the strip the form draws is literally the row order
 * the controller writes. There is no `position` field to submit and none to
 * get wrong.
 *
 * The count is checked against `length` here rather than left to the database,
 * because `turns_complete` is DEFERRABLE INITIALLY DEFERRED and fires at
 * COMMIT — by then the only honest answer is a flash message, and a field can
 * say it better.
 */
class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Schedule::class);
    }

    /**
     * `fallback_shift_id` and every turn are held to this agency's shifts,
     * which is exactly what the two paired FKs `(fallback_shift_id,
     * agency_id)` and `(shift_id, agency_id)` refuse — a turn cannot put
     * another agency's shift into this cycle. Shifts do not soft-delete and a
     * shift a schedule references cannot be removed (RESTRICT), so the picker
     * and the rule offer the same set with nothing to exclude.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $agency = app(Tenant::class)->id();

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('schedules', 'name')->where('agency_id', $agency),
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
     * One shift per day of the cycle, no more and no fewer.
     *
     * Silent when `length` or `turns` already failed: a second verdict on the
     * same submission competes with the first, and the label row holds one
     * line.
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
     * Keys travel as `turns[0]`, `turns[1]`, … so document order is already
     * position order; sorting and reindexing only makes a hand-built request
     * land on positions `0..n-1` rather than on whatever indices it chose,
     * which is the set `turns_complete` demands.
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
