---
paths:
  - 'app/Http/Requests/**'
---

# Requests

## One Form Request per write endpoint
Every write endpoint (store/update/etc.) gets its own FormRequest under app/Http/Requests. Requests for a distinct area get their own subdirectory (Auth/, Platform/); requests for the default tenant-scoped resource (Store/UpdateUserRequest) stay flat at the top level. authorize() calls Gate/Policy (often via $this->route('user') or similar for the target); prepareForValidation() normalizes input — e.g. lower-casing email the same way the model's own mutator does — before rules() and any custom rule closures run. Matches controllers.md's "Form Request -> action or model -> redirect with flash" flow.

## An `exists` rule must accept only what the picker offers
`Rule::exists` on a foreign key has to match the query that built the control, not just the table. `StoreUnitRequest`/`UpdateUnitRequest`'s `head_id` carries `->where('agency_id', …)->whereNull('deleted_at')->whereNull('separated_at')`, mirroring `UnitController::heads()`. Without the last two the request accepted a removed or separated employee by id, wrote it, and the unit then displayed **no head at all** — `->with('head')` resolves through the model's own soft-delete scope and answers null. A rule that accepts a value the screen cannot display is worse than one that refuses it, and the refusal costs nothing: `lang/en/validation.php` already answers `exists` with `Not found`.
