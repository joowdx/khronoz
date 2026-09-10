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
