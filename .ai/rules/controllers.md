---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Controller flow: Form Request, redirect with flash, JsonResource
Controllers: Form Request → action or model → redirect()->route(...)->with('success'|'error'); Inertia props through JsonResource::resolve(); cross-tenant requests must 404 (route binding under the scope).

## End placement uses a conditional update
Decision 28: PATCH employees/{employee}/deployments ends the open placement with one deployments()->whereNull('ends')->update(['ends' => $ends]) inside DB::transaction. Never read currentDeployment and then update the model: re-dating a closed row violates no constraint, so this predicate is the only stale-write guard. Eloquent maintains updated_at. Zero affected rows redirects to employees.show with error 'No open placement to end.'; 23514 translates to ends 'Before the current placement began.'. No new flash channel. The selected last day is included, unlike MoveEmployee's starts minus one.

## A translated database refusal needs its own transaction
Any controller that catches a `QueryException` to turn a refusal into a flash message must wrap the offending statement in `DB::transaction(fn () => ...)`.

Postgres marks the whole surrounding transaction aborted once a statement errors, and every later command answers `25P02: current transaction is aborted` until a rollback. Without the nested transaction the catch succeeds, the message is set, and then the redirect's own queries — or the test's next assertion — blow up with 25P02 instead.

`WorkgroupController::destroy` has done this since M3; `TerminalController::destroy` and both write actions on `TerminalEnrollmentController` needed the same fix after failing exactly this way. It applies to every refusal this app translates: 23001 (RESTRICT), 23514 (CHECK), 23P01 (exclusion).

The same mechanic is why `TestCase::assertDatabaseRefuses` runs its closure inside `DB::transaction` — a nested one compiles to a SAVEPOINT, so the rollback undoes only the failed statement.

## Ledger index sends aggregates, never a view
GET /ledgers lists withCount('workdays') and withSum of worked, tardy and undertime. Do not call Ledger::view() per row and do not send overtime on the index — overtime is a figure of the DTR page, computed once on show.
