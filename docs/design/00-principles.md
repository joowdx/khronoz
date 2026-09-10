# 09 — principles for a generic system

One rule: **variation between agencies is data, invariants are code.** Onboarding an agency must never need a deploy. Clockwork broke this with three duplicated compute branches (standard, shift, fallback) and a `span` column that changed meaning by case.

## Where each kind of variation lives

| Varies between agencies | Lives in | Not in |
|---|---|---|
| Org shape: departments, divisions, sections, units, none | `Workgroup` tree with a `kind` label, `Deployment` for placement | fixed Department and Division tables |
| Work patterns: 8–5, flexi, CWW, rotation, 24/7, remote day, Ramadan | `Shift` + `Schedule` + `Turn` + `Roster` rows | an arrangement enum with branches in the compute code |
| Who follows what | one `Roster` per employee, grouped by `Team` for a rotation cohort, selected by tag for an ad-hoc bulk action | schedules attached to workgroups or to a tag |
| Calendar | `Holiday` (national when owned by the platform agency, local otherwise), `Suspension` scoped by workgroup, `Exemption` per employee | a hardcoded holiday list |
| Terminals and vendors | `Terminal.protocol` with one driver per protocol, `Timelog` normalised to `(uid, time, state, mode)` | vendor-specific tables or columns |
| Sensible starting points | rows owned by the platform agency, copied into every new agency at onboarding, `origin_id` keeps the link | seeders per agency |
| Preferences: grace, trust device state, off day, remote day | typed `settings` json on `Agency`, defaults in code, bounded by law | an EAV `Setting` table |
| Reports and cutoffs | `Ledger` views with `period` and `work` parameters | 1–15 and 16–31 baked into columns |
| The law | one rules module (CSC) holding constants with effectivity dates | per-agency formulas |

## Principles

1. **One compute path.** Every arrangement is resolved to a `Shift` snapshot before a workday is computed. The engine compares punches to slots and knows nothing about "compressed" or "rotational". A new arrangement is new rows.
2. **Effective dates, never overwrite.** `Deployment`, `Roster`, `Enrollment` carry `starts` and `ends`. A change is a new range. History stays reconstructible and Postgres exclusion constraints keep ranges from overlapping.
3. **Snapshot the expectation, keep the fact raw.** `Workday.shift` json freezes what was expected; `Timelog` is immutable. Editing a shift changes the future only.
4. **Two scopes only: global and agency.** Ownership stops at the agency. Finer granularity comes from assignment (`Roster`, `Deployment`, `Suspension.workgroup_id`), not from workgroup-owned configuration.
5. **A type column when the shape is the same, a table when it differs.** `Holiday.type`, `Exemption.type`, `Workgroup.kind` are columns. `Suspension` is its own table because it has a time range and a workgroup scope that holidays do not.
6. **Bounded flexibility.** Agencies choose within the law. They cannot define new workday statuses, new formulas, or new fields in v1. Extra inert data goes in a `meta` json that no rule reads.
7. **Single database, `agency_id` on every table with paired foreign keys, a global scope keyed to the user's agency.** Platform users act inside a chosen agency, so the scope has one code path (02-access.md rule 3). Row-level security is available later without a schema change.
8. **Constants change by deploy with a date.** When the law an agency is under changes a threshold, the rules module gets a new value with an effectivity date, and workdays before that date keep computing under the old one. "The law an agency is under" is not one law: most of tier 1 below is Civil Service Commission arithmetic and has a different value, or no value, under the Labor Code (`../reference/csc-rules.md` and `dole-rules.md`).

## Three tiers of configuration

Three tiers. Only the middle one is data an agency can see and copy.

1. **Constants in code, in two sets** (revised by decision 32 — the original single set assumed every agency was under civil service rules). Both change by deploy with a date, never by a setting, and both live in the constants module; what an agency configures is *which regime's set applies to it*, never a value inside a set.

   **1a. Universal.** The 480-minute ordinary day — 8 hours under both Rule XVII §5 and Labor Code Art. 83.

   **1b. Regime constants**, selected by the agency's configured settings (decision 32) and **not** shared:

   | Constant | Civil service | Labor Code |
   | --- | --- | --- |
   | work week | 40 hours over 5 days | **no statutory week**; 8 ordinary hours a day and 24 continuous hours' rest after 6 consecutive work days |
   | night window | 18:00–06:00 (RA 11701) | **22:00–06:00** (Art. 86) |
   | overtime threshold | JC 2 s. 2015 gates: on-time arrival, 2-hour minimum, 12-hour cap | 8 hours a day, or **12** under a compliant compressed week, with a **48-hour weekly ceiling** |
   | premium multipliers | 1.25 and 1.5 | 1.25 ordinary, 1.30 rest/special day, 2.00 regular holiday, compounding |
   | compensatory credit | COC 1.0 and 1.5, 40-hour monthly and 120-hour balance caps | none |
   | habitual thresholds | 10 occurrences, 2 months, 2.5 days, 3 months; semester boundaries | **none** — no statutory occurrence counting exists |
   | offsetting | tardiness and absence cannot be self-offset, **but** approved compensatory service may offset undertime | undertime not offset by overtime on another day, no exception |
   | flexitime band | 7:00–19:00 | set by agreement, no statutory band |
   | hourly-rate divisor | 22-day month | by wage basis |
   | half-day and day fractions | CSC minutes-to-days table, seeded from the printed values because printed rounding is what auditors compare against | none |
   | record retention | no CSC figure found | **3 years** from the last entry |

   The night window is the sharpest of these: four hours a night, and it changes which minutes are *classified* as night work, not merely what they are paid. A night-hours total is therefore not portable between regimes.
2. **Platform default rows**, owned by the platform agency, read-only to agencies. National holidays are read directly, `agency_id IN (own, platform)`. Shifts and schedules are copied: onboarding copies the whole set into the new agency, later additions are copied from the Defaults screen, and every copy carries `origin_id` so the UI can show when a copy has diverged and refresh it on request. The set: shifts Standard 8–5, the seven flexitime options, CWW 7–6 and 8–7, Ramadan 7:30–3:30, Off, Remote; schedules Standard week, CWW Mon–Thu, CWW Tue–Fri, CWW Wed off, CWW Mon–Thu with a remote Friday. Rostering needs the copy, which the paired FK enforces.
3. **Agency settings.** Which schedules are rostered, the off day, grace minutes (default 0 — and **not** a free choice: CSC creates no grace period, and a lenient grace policy on a *fixed* schedule needs legal authority, though a genuine flexible schedule changes when lateness begins), trust of device state, remote day, overtime internal rules inside JC 2 s. 2015, pass slip conventions, workgroup-level suspensions, local holidays, and the regime keys of decision 32 that select tier 1b.


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
