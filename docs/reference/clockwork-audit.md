# 07 — clockwork audit (what not to repeat)

Source: github.com/joowdx/clockwork, models and migrations only, read 2026-09-08. Signature, Signer, Export, Attachment, Annotation skipped by instruction.

## Critical

- **Timesheet was a self-referential state machine.** A "full" row plus child rows per span all pointing at the parent via `timesheet_id`. Span was both a stored column and unsaved runtime state, and `getPeriod()` chose a relation method by string. Fifteen near-identical `hasMany(Timetable)` relations. khronoz: one `Ledger` row per employee-month, slices are view parameters `period` and `work`, never stored.
- **Timetable was a cache pretending to be a fact table.** `punch` json whose shape differed per arrangement, six derived booleans stored side by side, staleness detected by a sha512 digest of timelogs, scanners and holidays. khronoz: `Workday` with `Punch` rows, one status enum, `computed_at`, event-driven recompute.
- **Timelog had no FK to the employee.** Identity was the pair of PIN and device number matched against enrollment, with the device number copied into two tables and kept in sync by model events. The same manual join was rewritten four times; one version joined on the calendar date of the punch and broke on overnight shifts. khronoz: `timelogs.employee_id` resolved at ingest, nullable means unresolved.
- **Scheduling could not express rotation.** One date range, one days bucket, one arrangement string, two json blobs, per-employee times hidden in the pivot's json. Flexitime and compressed work week commented out of the enum. Resolution cached for 120 seconds per date and employee with no invalidation. khronoz: `Shift`, `Schedule` of `Turn`s, `Roster` with anchor.
- **Six booleans as a revision system.** `shadow`, `pseudo`, `masked`, `recast`, `cloned` via trigger, `orphan` generated, four global scopes hiding rows by default, semantics undocumented. `Prunable` deleted timelogs older than two years. khronoz: immutable timelogs, `source`, `voided_at` and `reason`, nothing pruned.

## Significant

- Three different things called `uid`: device number, biometric PIN, employee login handle.
- Timelogs had no timestamps, so nothing recorded when a punch was ingested. khronoz: `sync_id`.
- `ProcessTimetable` duplicated the matching algorithm three times and biased sorting by adding years equal to scanner priority.
- Global scope hiding interns on Employee; every relation had to remove it.
- Two authenticatable models, Employee and User. khronoz: one `User`.
- Deleting a user cascaded to delete holidays. Holiday lookup cached a day with no invalidation.
- Pivot tables singular except `assignments`. khronoz: plural everywhere.

## Kept

- ULID keys, Postgres-first.
- `TimelogState` and `TimelogMode` integers mirroring attlog. Raw int stored, enum cast with `tryFrom`, unknown never written back.
- Idempotent upsert on the attlog natural key.
- `Enrollment` as explicit employee-to-device mapping with a per-device PIN.
- Holiday partial-day handling, now its own `Suspension` model.
