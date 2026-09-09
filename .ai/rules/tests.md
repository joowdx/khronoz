---
paths:
  - 'tests/**'
---

# Tests

## Constraint tests use assertDatabaseRefuses
Constraint tests use assertDatabaseRefuses(sqlstate, fn) through the app connection; one test per constraint and trigger in docs/design/07-constraints.md.
