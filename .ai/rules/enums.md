---
paths:
  - 'app/Enums/**'
---

# Enums

## Enums mirror a varchar+CHECK; Permission also mirrors the TS union
A backed (string) enum with TitleCase case names, one per varchar column + CHECK (... IN (...)) constraint (docs/design/07-constraints.md). Permission additionally mirrors the `Permission` union in resources/js/types/index.d.ts and the `implied` map in resources/js/hooks/use-can.ts by hand, since neither TS file can import a PHP enum — tests/Unit/Enums/PermissionContractTest.php parses both files and enforces the match. Add a case, or an implies() edge, in all three places or that test fails.
