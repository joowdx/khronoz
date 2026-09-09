---
paths:
  - 'app/Http/Requests/**'
---

# Requests

## One Form Request per write endpoint
Every write endpoint (store/update/etc.) gets its own FormRequest under app/Http/Requests. Requests for a distinct area get their own subdirectory (Auth/, Platform/); requests for the default tenant-scoped resource (Store/UpdateUserRequest) stay flat at the top level. authorize() calls Gate/Policy (often via $this->route('user') or similar for the target); prepareForValidation() normalizes input — e.g. lower-casing email the same way the model's own mutator does — before rules() and any custom rule closures run. Matches controllers.md's "Form Request -> action or model -> redirect with flash" flow.
