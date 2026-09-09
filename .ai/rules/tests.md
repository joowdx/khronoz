---
paths:
  - 'tests/**'
---

# Tests

## Constraint tests use assertDatabaseRefuses
Constraint tests use assertDatabaseRefuses(sqlstate, fn) through the app connection; one test per constraint and trigger in docs/design/07-constraints.md.

## Skip a refusal test for PRIMARY KEY (id) on ULID tables
Don't write an assertDatabaseRefuses test for PRIMARY KEY (id) (e.g. agencies) even though docs/design/07-constraints.md lists it under "Defaults unless stated". IDs are application-generated ULIDs (HasUlids) — a colliding duplicate is not a real scenario, so the constraint exists in the DDL but isn't worth a dedicated test.
