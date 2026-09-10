<?php

namespace App\Http\Requests;

use App\Models\Unit;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
     * Upper-case the code before it is validated, so the uniqueness rule
     * below compares like with like — mirrors StoreAgencyRequest, otherwise
     * 'HR' and 'hr' would validate as two distinct, non-colliding codes even
     * though the units_agency_id_code_unique index is case-sensitive too.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => Str::upper((string) $this->input('code'))]);
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
     * The head must also be visible and have an open deployment. Rule::exists
     * reads the raw employees table, so both deleted_at and the correlated
     * deployment EXISTS are explicit. Pair employee and agency in that EXISTS
     * to mirror UnitController::heads without relying on Eloquent scopes.
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
            'head_id' => ['nullable', 'string', Rule::exists('employees', 'id')->where('agency_id', $agencyId)->whereNull('deleted_at')->where(fn ($query) => $query->whereExists(fn ($deployment) => $deployment
                ->selectRaw('1')->from('deployments')
                ->whereColumn('deployments.employee_id', 'employees.id')
                ->whereColumn('deployments.agency_id', 'employees.agency_id')
                ->whereNull('deployments.ends')))],
        ];
    }
}
