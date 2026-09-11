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

## Ingestion

Read 2026-09-11, the part the 2026-09-08 pass skipped. Audited by Claude and by an independent Codex
pass over the same files; 28 findings, converging. Line references are clockwork's, at the commit
read. **The whole ingestion path had two test files, both Laravel scaffolding examples.**

### The upsert is not an upsert

`Timelog::upsert($rows, ['device','uid','time','state','mode'], ['uid','time','state','mode'])` —
the conflict target *is* the natural key and the update list is a subset of it, so a conflict
rewrites the row with itself. `PostgresGrammar::compileUpsert()` always emits `do update set`, so
`upsert()` has no `DO NOTHING` path whatever you pass it. Three consequences: how many rows are new
is unknowable (`DO UPDATE` counts inserted and updated alike, and the reported count is the *input*
size); the row is mutable by design; and two rows with one natural key in a single statement raise
`21000`. The mitigation — `LazyCollection::unique()` — buffers the whole file in memory and dedupes
the *raw* rows before mapping, so two lines differing only in a discarded trailing column survive and
collide anyway. khronoz: `insertOrIgnoreReturning($values, $returning, $uniqueBy)`, accepted = rows
returned, duplicates = chunk size minus that, no dedupe pass in PHP.

### One bad line destroys the file

Row validation `throw`s inside `map()` on a lazy pipeline, so one malformed line discards every good
row in the file — and earlier chunks are already committed, because there is no transaction. The
column count is hard-coded at six; the timestamp is validated by `strtotime` round-trip, which is
timezone-dependent and rejects unpadded device output; `uid` is never checked for presence, so a
leading empty field stores a blank uid that collides with every other blank; `state`/`mode` are
gated by `is_numeric`, which accepts `1.5` and lets Postgres reject it mid-import. Nothing durable
records a run — the counts live only in a fired event, and one of the two events has no registered
listener at all. The file is read four times (`first()`, `last()`, `count()` after the stream was
consumed) and is never deleted afterwards. `earliest`/`latest` come from *input order*, so an
unordered export produces an inverted range that matches nothing and silently skips all post-ingest
work. A "September" import deliberately widens by a day each side. khronoz: per-row rejection into
`syncs.rejected`, `CHECK (received = accepted + duplicates + rejected)`, `min()`/`max()` over the
stream, one durable `syncs` row, the temp file deleted.

### One credential, five leaks

`scanners.pass` is a plain varchar with no cast; it is passed to a subprocess as `-K <pass>` (visible
in `ps`); it rides in `TimelogsSynchronized`'s `credentials` array, which is broadcastable; it is
POSTed to a remote host under `withoutVerifying()` alongside a bearer token; and `ActivityMonitor`
serialises that event into the persistent activity log. khronoz: `terminals.secret` is
`encrypted`-cast from day one, and decision 40 forbids argv, event and job payloads, activity
records, and disabled TLS.

### Four ways to destroy the raw record

`FlushTimelogs` hard-deletes (and dispatches its recompute *inside* the transaction); `Prunable`
deletes everything older than two years, scheduled every minute; `timelogs.device → scanners.uid` is
`cascadeOnDelete`, so deleting a scanner deletes its whole history; the self-FK is too. A fifth
mutates rather than deletes — that FK is also `cascadeOnUpdate` while `Scanner::saved()` pushes a
changed `uid` into `enrollment.device`, so renumbering a device rewrites the natural key of every
historical row in two tables. khronoz: `REVOKE DELETE, UPDATE ON timelogs`, restorable by
`db:grant` (decision 41), and RESTRICT on every FK.

### Identity resolved at read time

`timelogs` has no `employee_id`; identity is `(device, uid)` re-derived on every read through a
`HasOneThrough` that joins `timelogs` back onto itself. `enrollment` has no date range at all —
`UNIQUE (employee_id, scanner_id)` plus an `active` boolean — so a reissued UID or a re-enrolment can
only be recorded by editing history, and `cascadeOnDelete` on `employee_id` anonymises a deleted
employee's entire attendance record. `uid` is trimmed by an accessor *and* a mutator while the
database join uses the raw column, so a row stored with a trailing space never resolves while the UI
shows it matching; and `int(x.user_id)` in the downloader merges `007` with `7` and raises outright
on an alphanumeric id like `A17`. khronoz: `timelogs_resolve()` stores `employee_id` at insert,
`enrollments` carries `starts`/`ends` with two gist exclusion constraints, and decision 42 makes
`uid` an opaque string end to end.

### Operational

`downloader.py` calls `disable_device()` before reading and re-enables only in `finally`, under a
`Process::forever()` that the job's own 300s timeout can kill — leaving the terminal locked out until
someone re-enables it by hand. The whole device buffer is fetched every time; errors print to stdout
interleaved with the JSON records and are then matched as English strings; the remote callback is one
monolithic POST against a 12 MiB cap with no pagination. An import advances `scanners.synced_at`, so
importing an old export marks the device freshly synced. `ShouldBeUnique` silently *drops* a
concurrent second import. `Auth::user()` is read in a queued job constructor, and every error path is
a Filament notification, so ingestion cannot run outside an authenticated web request. Post-ingest
recompute runs synchronously after commit, so a listener failure fails the job and retries it as
though ingestion had failed.

### What ingest hands the deriver

Staleness is a sha512 of `json_encode()` over Eloquent models, so any change to a cast, accessor,
`$appends` or `$hidden` invalidates every stored digest at once — and the hash includes each
scanner's *print colour settings*, so restyling a device marks attendance stale. The timelogs it
hashes are read through four global scopes, so a row becoming `masked` does not mark its day stale.
The recompute set is rebuilt by re-querying `timelogs` over the run's time span rather than from what
the insert actually changed. khronoz: the ingest returns the `(employee_id, time)` pairs it inserted,
which is what the `RETURNING` clause is for.
