<?php

namespace App\Http\Requests;

use App\Models\Team;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A team is a name, a schedule and an anchor. Nothing else.
 *
 * No membership field and no date range, because the table has neither: the
 * rosters carrying a team's `team_id` *are* its membership (04-scheduling.md),
 * and a team is a standing definition rather than an arrangement with a start
 * and an end.
 *
 * `anchor` is cycle day 0 for the whole cohort. It is an ordinary date with no
 * bounds to check — anchoring a team in the past is how an existing rotation
 * is described, and anchoring it in the future is how a new one is announced.
 * Three teams on one 21-day cycle, anchored seven days apart, is the shape
 * this form exists to make possible.
 */
class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Team::class);
    }

    /**
     * `schedule_id` is held to this agency's schedules, which is what the
     * paired FK `(schedule_id, agency_id)` refuses. Schedules do not
     * soft-delete and one a team names cannot be removed while it does
     * (RESTRICT), so the picker and the rule offer the same set.
     *
     * @return array<string, mixed>
     */
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
