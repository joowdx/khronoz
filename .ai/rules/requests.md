---
paths:
  - 'app/Http/Requests/**'
---

# Requests

## One Form Request per write endpoint
Every write endpoint (store/update/etc.) gets its own FormRequest under app/Http/Requests. Requests for a distinct area get their own subdirectory (Auth/, Platform/); requests for the default tenant-scoped resource (Store/UpdateUserRequest) stay flat at the top level. authorize() calls Gate/Policy (often via $this->route('user') or similar for the target); prepareForValidation() normalizes input — e.g. lower-casing email the same way the model's own mutator does — before rules() and any custom rule closures run. Matches controllers.md's "Form Request -> action or model -> redirect with flash" flow.

## An `exists` rule must accept only what the picker offers
`Rule::exists` on a foreign key has to match the query that built the control, not just the table. `StoreWorkgroupRequest`/`UpdateWorkgroupRequest`'s `head_id` carries `->where('agency_id', …)->whereNull('deleted_at')->whereNull('separated_at')`, mirroring `WorkgroupController::heads()`. Without the last two the request accepted a removed or separated employee by id, wrote it, and the workgroup then displayed **no head at all** — `->with('head')` resolves through the model's own soft-delete scope and answers null. A rule that accepts a value the screen cannot display is worse than one that refuses it, and the refusal costs nothing: `lang/en/validation.php` already answers `exists` with `Not found`.

## Deployment ranges define employment (decision 28)
Supersedes the separated_at mechanism in “An exists rule must accept only what the picker offers”: employees has neither hired_at nor separated_at. Workgroup-head eligibility requires a non-deleted employee of this agency with an open deployment. Both workgroup requests must use a correlated EXISTS matching deployments.employee_id to employees.id, deployments.agency_id to employees.agency_id, and deployments.ends IS NULL; the picker uses whereHas('currentDeployment'). There is no separate hire-window validation. EndEmployeeDeploymentRequest validates ends >= the open start, with the database refusal translated by the controller.

## On update, an `exists` rule must also accept the row's own current value
Amends "An `exists` rule must accept only what the picker offers" — that rule is right for a store, and locks the row on an update.

`RemoveEmployee` soft-deletes. A calendar row pointing at the removed employee stays valid (the paired FK never sees an UPDATE) and the index null-guards the missing person, but an update rule carrying `->whereNull('deleted_at')` then answers `employee_id: Not found` for the id already on the row. Correcting a remarks typo on a 105-day maternity leave became impossible without choosing a *living* employee — which moves the leave onto them.

So on update: a value the picker offers, **or** the value the row already holds.

    Rule::exists('employees', 'id')
        ->where('agency_id', app(Tenant::class)->id())
        ->where(fn (Builder $query) => $query
            ->whereNull('deleted_at')
            ->orWhere('id', $this->route('exemption')->employee_id)),

Live in UpdateExemptionRequest and UpdateOvertimeRequest. A *new* value is still held to the picker; the existing one is not re-litigated.

Found by the 2026-09-11 four-way audit (Cursor).

## authorize() goes through the gate, never User::allows() directly
Always `$this->user()->can(…)` — a policy ability or a permission name. Never `$this->user()->allows(Permission::X)`.

`User::allows()` reads the permissions column and answers on its own. `Gate::before` (AppServiceProvider::configureAuthorization) is where a platform superuser's authority lives, and platform users are stored with `permissions = []` because superuser is the agency flag, not a held permission. So `allows()` in a FormRequest refuses every platform user.

ImportTimelogsRequest did exactly that, and it was the only one of 22 requests to do so — the single path that inserts pay-relevant rows refused the operator who may register the terminal and enrol people on it. Reproduced: sibling enrol 302, import 403.

Found by the 2026-09-11 four-way audit (Cursor).
