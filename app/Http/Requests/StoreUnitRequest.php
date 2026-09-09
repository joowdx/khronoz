<?php

namespace App\Http\Requests;

use App\Models\Unit;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Unit::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * parent_id and head_id are both paired FKs in the schema (parent_id,
     * agency_id) and (head_id, agency_id) — a plain Rule::exists only proves
     * the row exists somewhere, not that it belongs to this tenant, since it
     * queries the raw table rather than going through Unit::query() and
     * AgencyScope. The explicit ->where('agency_id', ...) is what actually
     * scopes it; without it a unit or employee id from another agency would
     * pass validation here and only be caught by the database's own paired
     * FK as an unhandled 23503.
     *
     * Cycles and self-parenting are left to the database (units_parent_not_self,
     * units_acyclic): both are pure structural checks with no concurrency
     * angle, and a real unit picker excludes the unit itself from its own
     * options anyway, so duplicating them here would be a second source of
     * truth for a case the UI will not normally reach.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $agencyId = app(Tenant::class)->id();

        return [
            'parent_id' => ['nullable', 'string', Rule::exists('units', 'id')->where('agency_id', $agencyId)],
            'kind' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', Rule::unique('units', 'code')->where('agency_id', $agencyId)],
            'name' => ['required', 'string', 'max:255'],
            'head_id' => ['nullable', 'string', Rule::exists('employees', 'id')->where('agency_id', $agencyId)],
        ];
    }
}
