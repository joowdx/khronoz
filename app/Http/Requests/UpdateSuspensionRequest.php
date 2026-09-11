<?php

namespace App\Http\Requests;

use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSuspensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('suspension'));
    }

    /**
     * There is deliberately **no uniqueness rule**, and that mirrors the
     * schema: `suspensions` has no key over `(agency_id, workgroup_id, date)`
     * because a morning window and an afternoon one on the same date are
     * ordinary, as is a division extending an agency-wide closure for its own
     * reason. The deriver takes the union.
     *
     * `starts` and `ends` are all-or-nothing — `suspensions_hours_paired`
     * refuses a half-set window, because "suspended from noon until nothing"
     * is not something anyone can act on and the deriver would have to guess
     * an end. `required_with` mirrors it in both directions so the refusal
     * lands on a field.
     *
     * `ends` is strictly after `starts`, unlike every date range in this
     * schema: a one-day range is a real thing, a zero-length window suspends
     * nothing.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workgroup_id' => [
                'nullable', 'string',
                Rule::exists('workgroups', 'id')->where('agency_id', app(Tenant::class)->id()),
            ],
            'date' => ['required', 'date_format:Y-m-d'],
            'starts' => ['nullable', 'required_with:ends', 'date_format:H:i'],
            'ends' => ['nullable', 'required_with:starts', 'date_format:H:i', 'after:starts'],
            'reason' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'declared_at' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'workgroup_id.exists' => 'Not found',
            'starts.required_with' => 'Give both a start and an end, or leave both blank for the whole day.',
            'ends.required_with' => 'Give both a start and an end, or leave both blank for the whole day.',
            'ends.after' => 'A suspension that ends when it starts suspends nothing.',
        ];
    }
}
