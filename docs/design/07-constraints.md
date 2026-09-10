# 10 — constraints: what the database enforces

Everything here is enforced by Postgres, not by Laravel. The app is one client among several (queue workers, imports, a future API); the database is the only place a rule cannot be bypassed. Four tiers, strongest first:

1. **Declarative**: primary keys, foreign keys, unique, check, exclusion constraints, generated columns. Preferred; always on; no code.
2. **Privileges**: what the app's database role may not do at all. Immutability lives here.
3. **Triggers**: only for the few rules a constraint cannot say (cycles, date containment across tables, cycle completeness).
4. **Application**: json slot shapes, business workflow. Everything in this tier is also re-checked by an audit query.

## Database identities protect the schema boundary

Migrations run as the database owner; the running application connects as
`chronoz`. The owner grants the app role the row and sequence access it
needs, including default privileges for tables a migration creates, then
revokes writes to `migrations`. The app role has no schema ownership or schema
creation privilege, so it cannot change a constraint, trigger or table, nor
write a false migration history. Owner credentials belong only to the deploy
and migration environment, never to a web, Octane or Horizon runtime.

This protects **schema integrity**, not authorization of otherwise valid row
writes. The app role may still read and change rows where this file does not
declare a table- or column-level `REVOKE`; a compromised application can
therefore still damage data within the constraints. Paired foreign keys prove
that rows cannot point across agencies, but application scopes currently decide
which same-agency rows a user may see. The workgroup-scoped visibility design in
02-access.md leaves Postgres row-level security open as a later backstop for
that second property; it must not be enabled until the request/job context and
the platform/global-row cases have a settled, testable policy.

## The pattern that makes it strict: carry the parent's key

A plain FK proves the row exists. It does not prove the row is the *right* one. Postgres can prove that too if the child repeats a column of the parent and the FK covers both columns. Every parent gets `UNIQUE (id, <column>)`, which is trivially satisfied since `id` is unique, and every child references the pair.

Three uses in khronoz:

| Child carries | Referencing | Proves |
|---|---|---|
| `agency_id` on every table | `(x_id, agency_id) → parent (id, agency_id)` | no row ever points across agencies |
| `employee_id` on punches and workdays | `(timelog_id, employee_id) → timelogs (id, employee_id)` | a punch only uses a timelog of the same employee |
| `uid`, `terminal_id`, `employee_id` on timelogs | `(enrollment_id, employee_id, terminal_id, uid) → enrollments (id, employee_id, terminal_id, uid)` | the resolved employee is the one enrolled under that UID on that device |

FKs use `MATCH SIMPLE`, the default: when any referencing column is null the check is skipped. That is what makes nullable links (unresolved timelogs, missed punches) work without special cases, and it is why global rows must not be null-owned, next section.

## Global rows belong to the platform agency, not to null

`agency_id` null on the shared tables would cost the `NOT NULL` declaration, need `UNIQUE NULLS NOT DISTINCT`, and put `OR agency_id IS NULL` in every scope. So the shared rows are owned by a real row: the one agency with `platform = true`, enforced by a partial unique index and protected by triggers. Consequences:

- National holidays, default shifts and schedules, superusers: `agency_id = platform`.
- An agency roster cannot reference a platform schedule, because `(schedule_id, agency_id)` would not match. **Copy on use is enforced by the FK**, not by discipline. The copy keeps `origin_id` pointing at the platform row it came from, the one deliberate cross-agency pointer, reference only.
- Scoping is `agency_id IN (own, platform)` for holidays and `agency_id = own` for everything else.
- Nothing operational hangs under the platform row: employees, workgroups, terminals and teams refuse it by trigger, and everything else needs one of those.
- The application never lists it: an Eloquent global scope on `Agency` excludes it, `Agency::platform()` reaches it.

## Extensions

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;   -- exclusion constraints mixing = and &&
```

ULIDs are `char(26)`; btree_gist handles them. Ranges use `daterange(starts, ends, '[]')`; a null `ends` is an open upper bound, so two open ranges always overlap and the exclusion constraint also gives "at most one open".

## Table by table

Defaults unless stated: every FK is `ON DELETE RESTRICT ON UPDATE RESTRICT`; every enum is `varchar` plus a `CHECK ... IN (...)`, mirrored by a PHP enum; every table has `agency_id NOT NULL` referencing `agencies` and `UNIQUE (id, agency_id)` so children can pair with it.

### agencies

```sql
PRIMARY KEY (id)
UNIQUE (code)
platform boolean NOT NULL DEFAULT false
CREATE UNIQUE INDEX agencies_platform ON agencies (platform) WHERE platform     -- at most one platform row
-- trigger agencies_platform_row: the platform row cannot be deleted; `platform` cannot change after insert
-- trigger agency_not_platform on employees, workgroups, terminals, teams, BEFORE INSERT OR UPDATE OF agency_id:
--   raise if the agency is the platform row
--   Milestone 2 applies it to employees and workgroups only; terminals arrive in M5, teams in M3
```

### workgroups

```sql
FOREIGN KEY (parent_id, agency_id) REFERENCES workgroups (id, agency_id)
FOREIGN KEY (head_id, agency_id)   REFERENCES employees (id, agency_id)
UNIQUE (agency_id, code)
CHECK (parent_id IS DISTINCT FROM id)
-- constraint trigger workgroups_acyclic, AFTER INSERT OR UPDATE OF parent_id, DEFERRABLE INITIALLY
--   IMMEDIATE: walk NEW.parent_id upward with a recursive CTE; raise if NEW.id is reached. AFTER
--   and deferrable, not BEFORE, because a BEFORE ... FOR EACH ROW trigger fires before its own row
--   exists and can't see other rows from the same statement, letting a multi-row INSERT close a
--   cycle undetected. UPDATE OF parent_id covers the other reachable violation, repointing an
--   existing row; a single-row INSERT can't close a cycle on its own.
```

### employees

```sql
UNIQUE (agency_id, number)
CHECK (sex IN ('male', 'female'))                                 -- nullable; the enum rule above, mirrored by App\Enums\Sex
tags jsonb NOT NULL DEFAULT '[]'                                  -- free-form agency labels; no rule reads them
CHECK (string_set_valid(tags))                                    -- array of distinct, non-empty strings
CHECK (jsonb_array_length(tags) <= 20)                            -- guarded in the DDL; see below
CREATE INDEX employees_tags ON employees USING gin (tags)         -- tags are filtered with `tags ? 'x'`
```

`tags` shape, decided in Milestone 2: the three rules `permissions` gets — array, every element a
string, no duplicates — plus non-empty elements, and a bound of 20. The shared function is
`string_set_valid(jsonb)`, not a second copy of `permissions_valid`, so the next jsonb label set
reuses it and brings its own bound. The empty array stays legal; it is the column default. A
per-tag character bound is the Form Request's job, not the database's — the length an agency may
type is a UI decision that will change, and a CHECK is the wrong place to keep a changing number.

```sql
CREATE FUNCTION string_set_valid(value jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_typeof(value) = 'array'
       AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(value) e WHERE jsonb_typeof(e) <> 'string')
       AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(value) e WHERE e = '')
       AND jsonb_array_length(value) = (SELECT count(DISTINCT e) FROM jsonb_array_elements_text(value) e);
$$;
```

The bound carries its own `jsonb_typeof(tags) <> 'array' OR` guard in the migration. Postgres does
not order CHECK evaluation, and `jsonb_array_length()` raises `22023` on a non-array rather than
returning false, so an unguarded bound could surface `22023` where `employees_tags_valid` owes
`23514`.

### deployments

```sql
UNIQUE (id, employee_id)                                             -- target for the parent FK below
FOREIGN KEY (employee_id, agency_id) REFERENCES employees (id, agency_id)
FOREIGN KEY (workgroup_id, agency_id)     REFERENCES workgroups (id, agency_id)
FOREIGN KEY (parent_id, employee_id) REFERENCES deployments (id, employee_id)
CHECK (ends IS NULL OR ends >= starts)
EXCLUDE USING gist (employee_id WITH =, daterange(starts, ends, '[]') WITH &&)
    WHERE (parent_id IS NULL)                                        -- deployments_no_overlap
EXCLUDE USING gist (employee_id WITH =, daterange(starts, ends, '[]') WITH &&)
    WHERE (parent_id IS NOT NULL)                                    -- deployments_no_overlapping_movements
TRIGGER deployments_nested  BEFORE INSERT OR UPDATE OF parent_id, starts, ends
```

Two partial exclusions, not one (decision 31). Each forbids overlap *within its class*, so an
employee has at most one substantive placement and at most one movement per date, and at most
one open row of each. A movement may overlap the substantive placement it departs from — that
nesting is a reassignment or detail — but never another movement.

Partition on `parent_id IS NULL`, never on any label: partitioning by a movement's *kind* would
let a "detail" and a "reassignment" overlap each other, which is wrong.

`UNIQUE (id, employee_id)` exists only so `(parent_id, employee_id)` can pair against it. That
makes "the parent is the same employee" structural rather than a trigger — the same device the
whole file uses for `(x_id, agency_id)`. What still needs `deployments_nested` (P0001) is
cross-row and cannot be a CHECK: a movement's range must sit inside its parent's, and a
movement's parent must itself be substantive, so there is no detail from a detail.

Decision 28 removes the former employment-window gap: employees has no separate hire or
separation dates. These deployment ranges **are** the employment history. The exclusion
constraints permit rehire after a gap and refuse two open substantive rows or two substantive
ranges sharing the same day.

Note for whoever implements decision 30: these ranges are access control, not only history, so
a corrupted range grants a workgroup records it must not see. Every write must be conditional
(`WHERE ... AND ends IS NULL`, or an expected-value predicate) rather than a read followed by an
update — no constraint here would refuse a stale rewrite.

### users

```sql
FOREIGN KEY (employee_id, agency_id) REFERENCES employees (id, agency_id)
UNIQUE (employee_id)
CREATE UNIQUE INDEX users_email ON users (lower(email))
permissions jsonb NOT NULL DEFAULT '[]'
CHECK (permissions_valid(permissions))                               -- array of distinct strings; the allowed set is the PHP enum
UNIQUE (id, agency_id)                                               -- target for the attestation FK
```

A superuser is `agency_id = platform` with no employee. The platform agency has no employees, so the FK already forbids linking a superuser to a person.

Permissions shape is a database check too: array, every element a string, no duplicate values. Which strings are allowed is the PHP enum, enforced by the application, not by this function.

```sql
CREATE FUNCTION permissions_valid(permissions jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_typeof(permissions) = 'array'
       AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(permissions) e WHERE jsonb_typeof(e) <> 'string')
       AND jsonb_array_length(permissions) = (SELECT count(DISTINCT e) FROM jsonb_array_elements_text(permissions) e);
$$;
```

### terminals

```sql
FOREIGN KEY (workgroup_id, agency_id) REFERENCES workgroups (id, agency_id)
UNIQUE (agency_id, code)
CREATE UNIQUE INDEX terminals_serial ON terminals (serial) WHERE serial IS NOT NULL
CHECK (kind IN ('terminal', 'usb'))
CHECK (protocol IN ('push', 'pull', 'file'))
```

### enrollments

```sql
FOREIGN KEY (employee_id, agency_id) REFERENCES employees (id, agency_id)
FOREIGN KEY (terminal_id, agency_id) REFERENCES terminals (id, agency_id)
CHECK (ends IS NULL OR ends >= starts)
EXCLUDE USING gist (terminal_id WITH =, uid WITH =, daterange(starts, ends, '[]') WITH &&)          -- a UID on a device is one person at a time
EXCLUDE USING gist (employee_id WITH =, terminal_id WITH =, daterange(starts, ends, '[]') WITH &&)  -- one UID per person per device at a time
UNIQUE (id, employee_id, terminal_id, uid)                                                          -- target for the timelog FK below
-- trigger enrollments_reresolve, AFTER INSERT OR UPDATE OF starts, ends, employee_id, uid, terminal_id:
--   re-run timelog resolution for the (terminal_id, uid) pair; see "Resolution is the database's job"
```

The first exclusion constraint's gist index also serves the resolution lookup `terminal_id = ? AND uid = ? AND range @> date`, so no extra index.

### syncs

```sql
FOREIGN KEY (terminal_id, agency_id) REFERENCES terminals (id, agency_id)
CHECK (trigger IN ('scheduled', 'manual', 'push', 'import'))
CHECK (finished_at IS NULL OR finished_at >= started_at)
CHECK (received = accepted + duplicates + rejected)
```

### timelogs

```sql
FOREIGN KEY (terminal_id, agency_id) REFERENCES terminals (id, agency_id)
FOREIGN KEY (sync_id)                REFERENCES syncs (id)
FOREIGN KEY (user_id)                REFERENCES users (id)
FOREIGN KEY (enrollment_id, employee_id, terminal_id, uid)
    REFERENCES enrollments (id, employee_id, terminal_id, uid)
UNIQUE (terminal_id, uid, time, state, mode)                      -- the attlog natural key; the upsert target
UNIQUE (id, employee_id)                                          -- target for the punch FK
CHECK ((enrollment_id IS NULL) = (employee_id IS NULL))           -- resolved means both, unresolved means neither
CHECK ((source = 'device') = (sync_id IS NOT NULL))
CHECK (source <> 'manual' OR user_id IS NOT NULL)                 -- MC 21 s. 1991: who recorded it
CHECK (voided_at IS NULL OR reason IS NOT NULL)
CHECK (state BETWEEN 0 AND 255) CHECK (mode BETWEEN 0 AND 255)    -- raw ints, unknown values allowed
-- trigger timelogs_resolve, BEFORE INSERT: sets enrollment_id and employee_id from the enrollment covering
--   (terminal_id, uid, time::date), or leaves both null; see "Resolution is the database's job"
```

Immutability is a privilege, not a trigger:

```sql
REVOKE DELETE ON timelogs FROM chronoz;
REVOKE UPDATE ON timelogs FROM chronoz;
GRANT  UPDATE (voided_at, reason) ON timelogs TO chronoz;
```

The app role can insert and void. It cannot change what the device said, it cannot say who punched, and it cannot delete. Same `REVOKE DELETE` on `syncs`.

### Resolution is the database's job

`employee_id` and `enrollment_id` are never written by the application. A `BEFORE INSERT` trigger fills them from the one enrollment that covers the punch. The two exclusion constraints on enrollments guarantee there is at most one, so the lookup is deterministic.

```sql
CREATE FUNCTION timelogs_resolve() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER AS $$
BEGIN
    SELECT e.id, e.employee_id
      INTO NEW.enrollment_id, NEW.employee_id
      FROM enrollments e
     WHERE e.terminal_id = NEW.terminal_id
       AND e.uid = NEW.uid
       AND daterange(e.starts, e.ends, '[]') @> NEW.time::date;
    RETURN NEW;     -- nothing found: both stay null, the timelog is unresolved and visible
END $$;

CREATE TRIGGER timelogs_resolve BEFORE INSERT ON timelogs
    FOR EACH ROW EXECUTE FUNCTION timelogs_resolve();
```

Every insert path gets it: push, pull, file import, manual entry. Whatever a client puts in those two columns is overwritten. With `ON CONFLICT DO NOTHING` the trigger still fires for a duplicate before the conflict is detected, which costs one index lookup per duplicate and nothing else.

When an enrollment appears or its range moves, the same rule is re-applied to the affected timelogs:

```sql
CREATE FUNCTION enrollments_reresolve() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER AS $$
BEGIN
    UPDATE timelogs t
       SET enrollment_id = e.id, employee_id = e.employee_id
      FROM enrollments e
     WHERE t.terminal_id = NEW.terminal_id AND t.uid = NEW.uid
       AND e.terminal_id = t.terminal_id AND e.uid = t.uid
       AND daterange(e.starts, e.ends, '[]') @> t.time::date
       AND (t.enrollment_id IS DISTINCT FROM e.id OR t.employee_id IS DISTINCT FROM e.employee_id);

    UPDATE timelogs t
       SET enrollment_id = NULL, employee_id = NULL
     WHERE t.terminal_id = NEW.terminal_id AND t.uid = NEW.uid
       AND t.enrollment_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM enrollments e
                        WHERE e.terminal_id = t.terminal_id AND e.uid = t.uid
                          AND daterange(e.starts, e.ends, '[]') @> t.time::date);
    RETURN NULL;
END $$;

CREATE TRIGGER enrollments_reresolve
    AFTER INSERT OR UPDATE OF starts, ends, employee_id, uid, terminal_id ON enrollments
    FOR EACH ROW EXECUTE FUNCTION enrollments_reresolve();
```

When `uid` or `terminal_id` themselves change, the function runs once more for the `OLD` pair. Deleting an enrollment that timelogs reference is blocked by the FK; end it with `ends` instead.

Both functions are `SECURITY DEFINER`, owned by the migration role, which is why the app role can lose `UPDATE` on those columns entirely. The paired FK on `(enrollment_id, employee_id, terminal_id, uid)` stays as a second lock: satisfied by construction, it catches a future bug in the function.

What the database does not do is queue the workday recompute. The application does that from `INSERT ... RETURNING id, employee_id, time` on ingest, and from the affected `(employee_id, time::date)` pairs after an enrollment change.

Why a trigger and not the alternatives: a generated column cannot read another table; `CREATE RULE` is legacy and does not compose with `ON CONFLICT`; resolving with a join inside each `INSERT ... SELECT` works but has to be remembered by every ingestion path.

### shifts, schedules, turns, teams

```sql
-- shifts
UNIQUE (agency_id, name)
FOREIGN KEY (origin_id) REFERENCES shifts (id) ON DELETE SET NULL      -- trigger origin_is_platform: the origin's agency must be the platform row
CHECK (required >= 0)
CHECK (flex >= 0)
CHECK (color BETWEEN 1 AND 8)                                       -- roster grid ramp index, stored not derived (04-scheduling.md rule 8)
CHECK (slots_valid(slots))                                          -- shape, order, past-24 cap; function below
CHECK (NOT remote OR jsonb_array_length(slots) = 0)                 -- a remote shift has no slots
CHECK (jsonb_array_length(slots) > 0 OR remote OR required = 0)     -- Off credits nothing
CHECK (jsonb_array_length(slots) > 0 OR flex = 0)

-- schedules
UNIQUE (agency_id, name)
FOREIGN KEY (origin_id) REFERENCES schedules (id) ON DELETE SET NULL   -- same trigger
CHECK (length BETWEEN 1 AND 366)
FOREIGN KEY (fallback_shift_id, agency_id) REFERENCES shifts (id, agency_id)

-- turns
FOREIGN KEY (schedule_id, agency_id) REFERENCES schedules (id, agency_id)
FOREIGN KEY (shift_id, agency_id)    REFERENCES shifts (id, agency_id)
UNIQUE (schedule_id, position)
CHECK (position >= 0)
-- constraint trigger turns_complete, DEFERRABLE INITIALLY DEFERRED, on turns and on schedules UPDATE OF length:
--   count(*) = schedules.length AND max(position) = schedules.length - 1

-- teams (Milestone 3)
UNIQUE (agency_id, name)
UNIQUE (id, agency_id)                                              -- target for the roster FK below
FOREIGN KEY (schedule_id, agency_id) REFERENCES schedules (id, agency_id)
anchor date NOT NULL
-- trigger agency_not_platform (see agencies): a team cannot hang under the platform row
```

Deferred means a schedule and its turns are written in one transaction and checked at commit.

Slot shape is a database check too. Times are `HH:MM` with hours past 24 rolling into the next days, capped at 72:00; pairs must be ordered and must not overlap; `window` is `[before <= 0, after >= 0]`.

```sql
CREATE FUNCTION slots_valid(slots jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
WITH s AS (
    SELECT e AS slot, i,
           split_part(e->>'in',  ':', 1)::int * 60 + split_part(e->>'in',  ':', 2)::int AS t_in,
           split_part(e->>'out', ':', 1)::int * 60 + split_part(e->>'out', ':', 2)::int AS t_out
      FROM jsonb_array_elements(slots) WITH ORDINALITY AS t(e, i)
     WHERE jsonb_typeof(e) = 'object'
       AND e->>'in'  ~ '^\d{1,2}:[0-5]\d$'
       AND e->>'out' ~ '^\d{1,2}:[0-5]\d$'
       AND jsonb_typeof(e->'window') = 'array' AND jsonb_array_length(e->'window') = 2
)
SELECT jsonb_typeof(slots) = 'array'
   AND jsonb_array_length(slots) = (SELECT count(*) FROM s)                  -- every element parsed
   AND NOT EXISTS (SELECT 1 FROM s
                    WHERE t_in >= t_out OR t_out > 72 * 60
                       OR coalesce((slot->>'grace')::int, 0) < 0
                       OR (slot->'window'->>0)::int > 0
                       OR (slot->'window'->>1)::int < 0)
   AND NOT EXISTS (SELECT 1 FROM s a JOIN s b ON b.i = a.i + 1 WHERE b.t_in < a.t_out);  -- ordered, no overlap
$$;
```

### rosters

```sql
FOREIGN KEY (employee_id, agency_id) REFERENCES employees (id, agency_id)
FOREIGN KEY (schedule_id, agency_id) REFERENCES schedules (id, agency_id)
FOREIGN KEY (team_id, agency_id)     REFERENCES teams (id, agency_id)     -- nullable: an ad-hoc set has no team
CHECK (ends IS NULL OR ends >= starts)
EXCLUDE USING gist (employee_id WITH =, daterange(starts, ends, '[]') WITH &&)
```

A roster's `schedule_id` and `anchor` **may differ** from those of the team its `team_id` names,
and nothing here forbids it. That is deliberate, not a missing constraint: `team_id` records where
the assignment came from, not a rule about what it produced, so an agency can slide one nurse's
anchor by a day without taking her off the cohort or rewriting the team. Resolution reads the
roster and never the team. A trigger could hold the two equal; it is deliberately absent.

### holidays, suspensions, exemptions

```sql
-- holidays
UNIQUE (agency_id, date, name)
CHECK (type IN ('regular', 'special', 'working', 'local'))
declared_at timestamp(0) NOT NULL                                  -- prospective application, Res. 2600838 §2.5

-- suspensions
FOREIGN KEY (workgroup_id, agency_id) REFERENCES workgroups (id, agency_id)
FOREIGN KEY (user_id) REFERENCES users (id)
CHECK ((starts IS NULL) = (ends IS NULL))
CHECK (starts IS NULL OR ends > starts)
declared_at timestamp(0) NOT NULL

-- exemptions
FOREIGN KEY (employee_id, agency_id) REFERENCES employees (id, agency_id)
FOREIGN KEY (user_id) REFERENCES users (id)
UNIQUE (id, employee_id)                                          -- target for the workday FK
CHECK ((starts IS NULL) = (ends IS NULL))
CHECK (starts IS NULL OR ends > starts)
CHECK (type IN ('leave', 'business', 'travel', 'cto', 'pass', 'personal', 'emergency'))   -- personal is recorded and printed but excuses nothing (README decision 19)
```

### overtimes

```sql
FOREIGN KEY (employee_id, agency_id) REFERENCES employees (id, agency_id)
FOREIGN KEY (user_id) REFERENCES users (id)
starts timestamp(0) NOT NULL, ends timestamp(0) NOT NULL          -- timestamps, so overnight authority is one row
date date GENERATED ALWAYS AS (starts::date) STORED
CHECK (ends > starts)
CHECK (mode IN ('pay', 'cto'))
EXCLUDE USING gist (employee_id WITH =, tsrange(starts, ends) WITH &&)
```

### ledgers

```sql
FOREIGN KEY (employee_id, agency_id) REFERENCES employees (id, agency_id)
UNIQUE (employee_id, month)
UNIQUE (id, employee_id, month)                                   -- target for the workday FK
CHECK (month = make_date(extract(year from month)::int, extract(month from month)::int, 1))
-- trigger ledgers_lock_complete, BEFORE UPDATE OF locked_at, when NEW.locked_at IS NOT NULL:
--   raise if EXISTS (punch of a workday of this ledger with expected_at > NEW.locked_at)
--   a month whose last shift ends past midnight cannot be locked before that out is due
-- trigger ledgers_unlock_clean, BEFORE UPDATE OF locked_at, when NEW.locked_at IS NULL:
--   raise if EXISTS (attestation of this ledger); remove the attestations first, on purpose
```

### attestations

```sql
FOREIGN KEY (ledger_id, agency_id) REFERENCES ledgers (id, agency_id)
FOREIGN KEY (user_id, agency_id)   REFERENCES users (id, agency_id)   -- a signer belongs to the same agency
UNIQUE (ledger_id, role)                                               -- one signature per role
CHECK (role ~ '^[a-z_]{1,32}$')                                        -- the allowed set is the agency's setting, checked by the app
-- trigger attestations_locked, BEFORE INSERT: raise unless the ledger's locked_at IS NOT NULL
REVOKE UPDATE ON attestations FROM chronoz                          -- a signature is added or removed, never edited
```

### workdays

```sql
month date GENERATED ALWAYS AS (make_date(extract(year from date)::int, extract(month from date)::int, 1)) STORED
FOREIGN KEY (ledger_id, employee_id, month) REFERENCES ledgers (id, employee_id, month)   -- right employee, right month
FOREIGN KEY (shift_id, agency_id)           REFERENCES shifts (id, agency_id)
FOREIGN KEY (exemption_id, employee_id)     REFERENCES exemptions (id, employee_id)       -- the exemption is this person's
UNIQUE (employee_id, date)
UNIQUE (id, employee_id)                                          -- target for the punch FK
CHECK (status IN ('present', 'absent', 'off', 'holiday', 'exempt', 'suspended', 'remote'))
CHECK (worked >= 0 AND tardy >= 0 AND undertime >= 0 AND excess >= 0 AND night >= 0)
CHECK (shift IS NULL OR jsonb_typeof(shift) = 'object')
```

`STORED` is spelled out because Postgres 18 defaults generated columns to `VIRTUAL`, and virtual columns cannot be indexed or referenced by a foreign key.

### punches

```sql
FOREIGN KEY (workday_id, employee_id) REFERENCES workdays (id, employee_id) ON DELETE CASCADE   -- derived rows follow their workday
FOREIGN KEY (timelog_id, employee_id) REFERENCES timelogs (id, employee_id)                     -- same person, and resolved
UNIQUE (workday_id, slot, kind)
CREATE UNIQUE INDEX punches_timelog ON punches (timelog_id) WHERE timelog_id IS NOT NULL         -- one timelog fills one slot side, ever
CHECK ((timelog_id IS NULL) = (actual_at IS NULL))
CHECK (kind IN ('in', 'out'))
CHECK (slot > 0)
-- trigger punches_timelog_live, BEFORE INSERT: raise if the timelog has voided_at set
```

The composite FK to timelogs does more than it looks: an unresolved timelog has `employee_id` null, so it can never match a punch's non-null `employee_id`. A punch can only ever use a resolved timelog.

## The complicated relationships, answered

| Question | Enforced by |
|---|---|
| Is this timelog's employee the one enrolled under that UID on that device? | composite FK on `(enrollment_id, employee_id, terminal_id, uid)` |
| On that date? | the database sets it: `timelogs_resolve` picks the enrollment covering `time::date`, `enrollments_reresolve` redoes it when enrollments change |
| Can a UID be two people at once, or a person hold two UIDs on one device at once? | two exclusion constraints on enrollments |
| Does this punch use a timelog of the same employee, resolved, unused elsewhere, not voided? | composite FK, composite FK, partial unique index, trigger |
| Is this workday in the right ledger? | generated `month` plus the three-column FK |
| Is this exemption the right person's? | composite FK on `(exemption_id, employee_id)` |
| Can anything point across agencies? | `agency_id` on every table, every FK paired with it |
| Can an agency roster a platform default without copying it? | no, the paired FK fails on the agency mismatch |
| Can two deployments, rosters or overtime windows overlap? | exclusion constraints with btree_gist |
| Can a schedule be half-built? | deferred constraint trigger `turns_complete` |
| Can a workgroup be its own ancestor? | trigger `workgroups_acyclic` |
| Can a month be locked while a cross-midnight out is still due? | trigger `ledgers_lock_complete` |
| Can someone certify moving numbers, or move certified numbers? | trigger `attestations_locked`, trigger `ledgers_unlock_clean` |
| Can a signer be from another agency, or sign a role twice? | paired FK on `(user_id, agency_id)`, `UNIQUE (ledger_id, role)` |
| Can anyone alter or delete a timelog the device recorded, or claim it for another person? | the app role has no `DELETE`, `UPDATE` only on `voided_at` and `reason`; resolution columns are written by trigger alone |

## Cost

One extra `agency_id` column and one `UNIQUE (id, agency_id)` index per table, one gist index per exclusion constraint, eleven triggers. Writes on `timelogs` gain one indexed lookup against enrollments per row for resolution and one FK check. Nothing here is measurable next to the upsert itself.

## Laravel notes

- Composite FKs: `$table->foreign(['employee_id', 'agency_id'])->references(['id', 'agency_id'])->on('employees')`.
- Generated columns: `->storedAs(...)`. Never `->virtualAs()` for anything indexed or referenced.
- Exclusion constraints, `CHECK`, partial unique indexes, grants and triggers: `DB::statement()` inside the migration. Wrap each in `Schema::hasTable` guards only if the migration must be re-runnable; otherwise let it fail loudly.
- `Timelog` has no `employee_id` or `enrollment_id` in `$fillable`, and the model never sets them; the database does. Ingestion reads them back with `RETURNING`.
- Every constraint and trigger gets one Pest test that performs the violation, or the insert, and asserts what the database did. That is the test suite for this file.
- The app connection uses `chronoz`; migrations run as the owner connection. `config/database.php` defines both identities, and migrations refuse to run unless they use the owner connection.
