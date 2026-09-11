<?php

namespace App\Http\Requests;

use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The store rules with the row's own name ignored.
 *
 * Re-anchoring a team here changes the definition only. It does not touch the
 * rosters already issued from it — those keep their own `anchor` and may
 * legitimately diverge (07-constraints.md deliberately has no trigger holding
 * the two equal), so a past rotation stays answerable and re-issuing is an act
 * of its own on the roster grid.
 */
class UpdateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('team'));
    }

    /**
     * `.ai/rules/requests.md` asks an update's `exists` rule to accept the
     * value the row already holds. Here it does by construction: `schedules`
     * does not soft-delete, and a schedule a team names cannot be removed
     * while it does, so `where('agency_id', …)` covers the current value as
     * well as every value the picker offers.
     *
     * @return array<string, mixed>
     */
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
