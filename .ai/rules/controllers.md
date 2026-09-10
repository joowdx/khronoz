---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Controller flow: Form Request, redirect with flash, JsonResource
Controllers: Form Request → action or model → redirect()->route(...)->with('success'|'error'); Inertia props through JsonResource::resolve(); cross-tenant requests must 404 (route binding under the scope).

## End placement uses a conditional update
Decision 28: PATCH employees/{employee}/deployments ends the open placement with one deployments()->whereNull('ends')->update(['ends' => $ends]) inside DB::transaction. Never read currentDeployment and then update the model: re-dating a closed row violates no constraint, so this predicate is the only stale-write guard. Eloquent maintains updated_at. Zero affected rows redirects to employees.show with error 'No open placement to end.'; 23514 translates to ends 'Before the current placement began.'. No new flash channel. The selected last day is included, unlike MoveEmployee's starts minus one.
