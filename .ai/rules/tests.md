---
paths:
  - 'tests/**'
---

# Tests

## Constraint tests use assertDatabaseRefuses
Constraint tests use assertDatabaseRefuses(sqlstate, fn) through the app connection; one test per constraint and trigger in docs/design/07-constraints.md.

## Skip a refusal test for PRIMARY KEY (id) on ULID tables
Don't write an assertDatabaseRefuses test for PRIMARY KEY (id) (e.g. agencies) even though docs/design/07-constraints.md lists it under "Defaults unless stated". IDs are application-generated ULIDs (HasUlids) — a colliding duplicate is not a real scenario, so the constraint exists in the DDL but isn't worth a dedicated test.

## SQLSTATEs to expect
| SQLSTATE | Meaning | When |
| --- | --- | --- |
| 23505 | unique_violation | a duplicate value in a UNIQUE/PRIMARY KEY column |
| 23502 | not_null_violation | a required column left null |
| 23514 | check_violation | a CHECK constraint failed |
| 23503 | foreign_key_violation | **insert/update side** — a referencing row's FK column points at a parent that does not exist |
| 23001 | restrict_violation | **delete/update side** — a parent row is removed (or its key changed) while an `ON DELETE RESTRICT` FK still references it |
| 42501 | insufficient_privilege | the app role attempted something only the owner role may do |
| P0001 | raise_exception | a project trigger's own RAISE (e.g. agencies_platform_row) |

Every paired FK in this project defaults to `ON DELETE RESTRICT ON UPDATE RESTRICT` (docs/design/07-constraints.md's global default, not stated per table), so **23001 is the dominant delete-refusal code** to expect across the ~25 tables in Milestones 2–8 — RESTRICT cannot defer the check the way NO ACTION can, which is why it raises its own distinct SQLSTATE instead of reusing 23503. Reserve 23503 for the insert/update side, where a child row's FK column names a parent id that isn't there at all.

## Scout searches return nothing in tests unless you opt into a real driver
phpunit.xml pins SCOUT_DRIVER=null for the whole suite, so unrelated tests never need a search backend. Laravel\Scout\Engines\NullEngine silently returns zero results no matter what data exists — worse, combined with a Scout Builder::query() callback (used for eager-loading), Builder::getTotalCount() takes a different code path that runs an empty whereIn('id', []), i.e. a literal `0 = 1`, so ->paginate() reports 0 total too. Any test that exercises a controller calling Model::search() must first do `config(['scout.driver' => 'database'])` (or fake Scout), or it is silently testing NullEngine's empty result, not the controller.
