---
paths:
  - 'app/Enums/**'
---

# Enums

## Enums mirror a varchar+CHECK; Permission also mirrors the TS union
A backed (string) enum with TitleCase case names, one per varchar column + CHECK (... IN (...)) constraint (docs/design/07-constraints.md). Permission additionally mirrors the `Permission` union in resources/js/types/index.d.ts and the `implied` map in resources/js/hooks/use-can.ts by hand, since neither TS file can import a PHP enum — tests/Unit/Enums/PermissionContractTest.php parses both files and enforces the match. Add a case, or an implies() edge, in all three places or that test fails.

## An enum may read a raw integer column, and the label test only sees enums that exist
Most enums here mirror a varchar + CHECK. AttlogState and AttlogMode do not: `timelogs.state` and `timelogs.mode` are unsignedTinyIntegers bounded by nothing, because 03-terminals.md rule 6 keeps the raw device integers so unfamiliar firmware survives import. Those enums are a *reading* of the column, never a constraint on it — cast with `tryFrom`, never written back, and each carries a static `describe(int)` giving documented codes their label and printing anything else as its own number. Do not add a CHECK to make them match the usual pattern.

The trap worth remembering: EnumLabelContractTest finds labelled enums by globbing `app/Enums/*.php` for a `label()`. It therefore catches a vocabulary that has drifted **from** its enum, and is blind to one that never had an enum at all. `resources/js/lib/attlog.ts` held both label maps in TypeScript for a whole milestone with the suite green. When you find display words in a `.ts`/`.tsx` file, do not assume the guard cleared them — check whether the PHP enum exists.
