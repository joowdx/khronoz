# 09 — principles for a generic system

One rule: **variation between agencies is data, invariants are code.** Onboarding an agency must never need a deploy. Clockwork broke this with three duplicated compute branches (standard, shift, fallback) and a `span` column that changed meaning by case.

## Where each kind of variation lives

| Varies between agencies | Lives in | Not in |
|---|---|---|
| Org shape: departments, divisions, sections, none | `Unit` tree with a `kind` label, `Deployment` for placement | fixed Department and Division tables |
| Work patterns: 8–5, flexi, CWW, rotation, 24/7, remote day, Ramadan | `Shift` + `Schedule` + `Turn` + `Roster` rows | an arrangement enum with branches in the compute code |
| Who follows what | one `Roster` per employee, grouped by `Team` for a rotation cohort, selected by tag for an ad-hoc bulk action | schedules attached to units or to a tag |
| Calendar | `Holiday` (national when owned by the platform agency, local otherwise), `Suspension` scoped by unit, `Exemption` per employee | a hardcoded holiday list |
| Terminals and vendors | `Terminal.protocol` with one driver per protocol, `Timelog` normalised to `(uid, time, state, mode)` | vendor-specific tables or columns |
| Sensible starting points | rows owned by the platform agency, copied into every new agency at onboarding, `origin_id` keeps the link | seeders per agency |
| Preferences: grace, trust device state, off day, remote day | typed `settings` json on `Agency`, defaults in code, bounded by law | an EAV `Setting` table |
| Reports and cutoffs | `Ledger` views with `period` and `work` parameters | 1–15 and 16–31 baked into columns |
| The law | one rules module (CSC) holding constants with effectivity dates | per-agency formulas |

## Principles

1. **One compute path.** Every arrangement is resolved to a `Shift` snapshot before a workday is computed. The engine compares punches to slots and knows nothing about "compressed" or "rotational". A new arrangement is new rows.
2. **Effective dates, never overwrite.** `Deployment`, `Roster`, `Enrollment` carry `starts` and `ends`. A change is a new range. History stays reconstructible and Postgres exclusion constraints keep ranges from overlapping.
3. **Snapshot the expectation, keep the fact raw.** `Workday.shift` json freezes what was expected; `Timelog` is immutable. Editing a shift changes the future only.
4. **Two scopes only: global and agency.** Ownership stops at the agency. Finer granularity comes from assignment (`Roster`, `Deployment`, `Suspension.unit_id`), not from unit-owned configuration.
5. **A type column when the shape is the same, a table when it differs.** `Holiday.type`, `Exemption.type`, `Unit.kind` are columns. `Suspension` is its own table because it has a time range and a unit scope that holidays do not.
6. **Bounded flexibility.** Agencies choose within the law. They cannot define new workday statuses, new formulas, or new fields in v1. Extra inert data goes in a `meta` json that no rule reads.
7. **Single database, `agency_id` on every table with paired foreign keys, a global scope keyed to the user's agency.** Platform users act inside a chosen agency, so the scope has one code path (02-access.md rule 3). Row-level security is available later without a schema change.
8. **Constants change by deploy with a date.** When CSC changes a threshold, the rules module gets a new value with an effectivity date, and workdays before that date keep computing under the old one.

## Three tiers of configuration

Three tiers. Only the middle one is data an agency can see and copy.

1. **Constants in code.** 480-minute day, 40-hour week, 7:00–19:00 flexitime band, 18:00–06:00 night window, the overtime gates (on-time arrival, 2-hour minimum, 12-hour cap), 1.25 and 1.5 multipliers, COC 1.0 and 1.5 with 40 and 120 caps, habitual thresholds (10 occurrences, 2 months, 2.5 days, 3 months), half-day rules, no offsetting, semester boundaries, 22-day month. These change when the law changes, which is a code change with a date, not a setting. They live in one constants class, with the CSC minutes-to-days conversion table seeded from the printed values because printed rounding is what auditors compare against.
2. **Platform default rows**, owned by the platform agency, read-only to agencies. National holidays are read directly, `agency_id IN (own, platform)`. Shifts and schedules are copied: onboarding copies the whole set into the new agency, later additions are copied from the Defaults screen, and every copy carries `origin_id` so the UI can show when a copy has diverged and refresh it on request. The set: shifts Standard 8–5, the seven flexitime options, CWW 7–6 and 8–7, Ramadan 7:30–3:30, Off, Remote; schedules Standard week, CWW Mon–Thu, CWW Tue–Fri, CWW Wed off, CWW Mon–Thu with a remote Friday. Rostering needs the copy, which the paired FK enforces.
3. **Agency settings.** Which schedules are rostered, the off day, grace minutes (default 0), trust of device state, remote day, overtime internal rules inside JC 2 s. 2015, pass slip conventions, unit-level suspensions, local holidays.


## Onboarding test

Every design change must keep these five agencies onboardable without a code change:

| Agency | Shape | Needs |
|---|---|---|
| A | departments → divisions → employees, 8–5 | defaults only |
| B | divisions only, staggered 7–4 and 9–6 flexitime | two shifts, two roster sets built from a tag filter |
| C | hospital, three 8-hour shifts rotating weekly, 24/7 | schedule of length 21, three anchors, a slot ending past 24:00, later `night` minutes |
| D | LGU on a Tuesday–Friday CWW with local holidays | 10-hour shift, CWW schedule with fallback (D2), local `Holiday` rows |
| E | national agency under OP MC 114 with a remote Friday | schedule whose Friday turn is a `remote` shift (D1) |

Timezone is Asia/Manila throughout. Terminal times arrive as naive local time and are stored that way. Serving another jurisdiction would mean another rules module, which is a different product, not a setting.
