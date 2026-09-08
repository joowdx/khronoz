# khronoz — design (draft, 2026-09-08)

Single-word models, plural tables, ULID keys, Postgres. Every table carries `agency_id`; the agency is the tenant. Global defaults belong to the platform agency row, never to null. Integrity is enforced in Postgres, see 07-constraints.md.

Read 00-principles.md first, then one file per concern. Each concern file has a mermaid ERD.

| File | Concern | Models |
|---|---|---|
| 00-principles.md | how it stays generic | |
| 01-organization.md | who and where | Agency, Unit, Deployment, Employee, Group, Member |
| 02-access.md | who logs in | User |
| 03-devices.md | raw timelogs in | Device, Enrollment, Template, Sync, Timelog |
| 04-scheduling.md | what was expected | Shift, Schedule, Turn, Roster |
| 05-calendar.md | what changed the expectation | Holiday, Suspension, Exemption, Overtime |
| 06-attendance.md | what happened | Workday, Punch, Ledger, Attestation |
| 07-constraints.md | what Postgres enforces | |

Inputs the design answers to are in `../reference/`: `csc-rules.md`, the law as verified on 2026-09-08, and `clockwork-audit.md`, the predecessor's mistakes. Reference files are read, not designed in.

Vocabulary: a **timelog** is what the device recorded; a **punch** is one matched slot side of a workday. Never the other way round.

## Open decisions

1. Decided 2026-09-08: punches are a table. The FKs in 07-constraints.md need rows, json cannot carry them.
2. Decided 2026-09-08: Inertia React for the web. Mobile comes later as a token API over the same action classes; Inertia controllers stay thin.
3. Decided 2026-09-08: `Device`.
4. Decided 2026-09-08: `Template` is phase 2; v1 needs `Enrollment` only. The device user id column is `uid`, the value the attlog carries.
5. Decided 2026-09-08: `Turn` rows.
6. Decided 2026-09-08: `Exemption`.
7. Slots as fixed-shape json on the shift, copied into the workday snapshot. Shape settled in 04-scheduling.md: per pair `in, out, grace, window`; times past 24:00 (`"30:00"` is 06:00 the next day) replace the `overnight` flag and cover 24- and 48-hour duties; `flex` minutes on the shift replaces the wide-window flexi idea. Examples there for standard, flexitime, CWW, hospital rotation, 12-hour, 24/48 duty, Ramadan. Confirmed 2026-09-08.
8. Decided 2026-09-08: `Unit`.
9. Decided 2026-09-08: `Ledger` is a table, one row per employee-month created by the first workday; `workdays.ledger_id` is a required FK.
10. Decided 2026-09-08: all deltas D1–D13 accepted and folded into the concern files. D5 stores raw `excess` on the workday, compensable overtime is the ledger view against `Overtime`. The table below is kept as the audit trail.
11. Decided 2026-09-08 under "if it is a real improvement then we do it": global rows are owned by the platform agency row instead of `agency_id` null; every table carries `agency_id` with paired foreign keys; timelog resolution is done by database trigger, not by the app. Confirmed 2026-09-08.

12. Decided 2026-09-08: D8 is an `attestations` table, one row per role per ledger, roles listed per agency in settings, signers resolved from `Unit.head_id`. Attestations require a locked ledger; unlocking requires removing them first.
13. Decided 2026-09-08: cross-midnight punches belong to the workday the shift started on, in that month; a timelog at T can only serve dates T − 3 to T; a ledger cannot lock while an out is still pending. CS Form 48 prints later-day punches with a day marker.
14. Decided 2026-09-08: the shared-row owner is the **platform** agency, found by a `platform` boolean with a partial unique index, not by a magic code. Its rough edges are handled: an Eloquent global scope hides it from every agency list; superusers act inside a chosen agency so scoping has one code path; defaults are copied into each new agency at onboarding and copies carry `origin_id`, so drift is visible and refreshable; employees, units, devices and groups refuse the platform row by trigger. Say if not.

15. Decided 2026-09-08: tier 3 agency settings are one `settings` jsonb column on `agencies`, shape checked by a Postgres function, read through one typed PHP class; an `audits` table filled by a generic row-level trigger on rosters, exemptions, overtimes, enrollments, timelog voids, ledger locks, attestations, shifts and schedules is in v1, with `user_id` from a session variable the app sets per request; employee logins are created by HR invite only, and an agency whose staff never log in drops `employee` from its attestation chain.

## Deltas from the CSC review, applied 2026-09-08

| # | Change | Rule it serves | Recommendation |
|---|---|---|---|
| D1 | `Shift.remote` boolean. A remote shift expects no punches, credits the day on attestation, and never yields overtime | Flexiplace; OP MC 114 common WFH day; no COC/OT on WFH | Adopt. A WFH Friday is a turn in a schedule, so it belongs on the shift, not on the workday |
| D2 | `Schedule.fallback_shift_id`, nullable. When a holiday or suspension lands on an Off turn of the week, the other turns of that ISO week resolve to the fallback shift, prospectively from `declared_at` | Res. 2600838 §2.3 and §2.5 | Adopt. Recompute triggers must widen from the date to the whole week for CWW rosters |
| D3 | `declared_at` on Holiday and Suspension | §2.5 "shall not be subject to recomputation"; Omnibus Rules on Leave §32 | Adopt. Drives both the CWW rule and the partial-day suspension charge |
| D4 | Exemption gets `starts` and `ends` times, null = whole day, replacing `period am/pm/full`. AM and PM become derived | Friday prayer 10:00–14:00 crosses the noon line; partial pass slips; lactation breaks if an agency punches breaks | Adopt. Keep `type`; `travel` must suppress overtime (JC 2 s. 2015 §7.3) |
| D5 | New model `Overtime`: the written authority. Workday keeps raw excess minutes; the Ledger overtime view is actual ∩ authorized, gated by on-time arrival and the 2-hour minimum, split into scheduled-workday hours and rest-day or holiday hours so payroll applies 1.25 or 1.5 and COC 1.0 or 1.5 | JC 2 s. 2015 §3, §10.1, §13.2 | Adopt. Without it the system reports overtime CSC says is not compensable. Single word kept; `Authority` is the alternative |
| D6 | `Workday.night` minutes worked inside 18:00–06:00 | RA 11701 | Adopt. Cheap to compute from punches, impossible to reconstruct later from totals |
| D7 | Write the daily rules into 06: no offsetting; morning absence is a tardy occurrence; afternoon absence is an undertime occurrence; suspension truncates expected slots at `starts` and charges the absent from shift start to `declared_at`; leave days = minutes ÷ 480 via the printed table | csc-rules.md C and E | Adopt as text, no schema |
| D8 | Ledger gains `certified_at` (employee) and `verified_by` (supervisor) beside `locked_at`, and monthly counts of tardy occurrences, undertime occurrences and unauthorized absent days as derived values | CS Form 48; habitual thresholds counted per month over a semester | Adopt. This settles open decision 9 toward a table |
| D9 | `Timelog.user_id` for manual entries, with the existing `reason` | MC 21 s. 1991 "names and signatures ... subject to verification" | Adopt |
| D10 | Shift and Schedule rows owned by the platform agency as global defaults, copy on use | 00-principles.md, three tiers | Applied through decision 11 |
| D11 | Constants module for tier 1 values, with the minutes-to-days lookup seeded from the printed CSC table | 00-principles.md, three tiers | Adopt |

Not needed in v1: employee position level for OT and NSD eligibility (payroll decides), leave balance accounting, peso computation.

