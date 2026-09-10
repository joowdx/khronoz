# 09 — DOLE rules that touch time

Reviewed 2026-09-10; corrected and **verified in primary text on 2026-09-10/11**:
Primary text was read and verified for: Labor Code Arts. 83, 84, 85, 86, 87, 88, 91, 92, 93; DOLE's **Handbook on Workers' Statutory Monetary Benefits (2024 Edition)**; **Omnibus Rules Implementing the Labor Code, Book III, Rule I §§3–7, Rule IV §§3 & 10 (successive regular holidays), and Rule X §§6–12 (employment and time records, 3-year retention)**; DOLE Department Advisory 02 s. 2004 (compressed workweek); DO 237 s. 2022 (telecommuting); RA 10151 / DO 119-12 (night workers); and NAP General Records Disposition Schedule Item 44 (government DTR retention).

Marks: **★** not read in primary text by anyone; **☆** not read by the author but independently cross-checked by the review. Most rules now carry primary statutory or IRR verification.

Companion to `csc-rules.md`. Where the two disagree the difference is a per-agency setting, never a code branch — `../design/00-principles.md`, "Three tiers of configuration".

Scope: what the engine must compute. **Every rate below is payroll's, not khronoz's** — `../design/README.md` puts OT and NSD eligibility and peso computation out of v1. They are recorded because they decide *which minutes must be counted separately*, which is khronoz's job.

## A. Baseline

| Rule | Value | Source |
|---|---|---|
| Ordinary hours | "shall not exceed eight (8) hours a day" — the cap on *ordinary* hours, not a ceiling on work; Arts. 87 and 89 permit overtime | Art. 83 |
| Hours worked | "all time during which an employee is required to be on duty or to be at a prescribed workplace" and "all time during which an employee is suffered or permitted to work" | Art. 84 |
| Meal period | "not less than sixty (60) minutes time-off for their regular meals" — time off, so not compensable | Art. 85 |
| Shortened meal period | not less than 20 minutes, only in four named cases (non-manual work; establishment operating 16+ hours a day; emergency or urgent machinery work; preventing loss of perishables) — **and then it is credited as compensable hours worked** | Book III Rule I §7 |
| Coffee breaks | "rest periods or coffee breaks running from five (5) to twenty (20) minutes shall be considered as compensable working time" | Book III Rule I §7 |
| Weekly rest day | "a rest period of not less than twenty-four (24) consecutive hours after every six (6) consecutive normal work days" | Art. 91 |
| Rest day work | permitted in six named circumstances (emergency, urgent machinery work, abnormal workload, perishables, continuous operations, analogous cases) | Art. 92 |
| Offsetting | "Undertime work on any particular day shall not be offset by overtime work on any other day" | Art. 88 |
| Grace period | none in the Labor Code; any grace is company policy | — |
| Work week | **no statutory 40-hour week.** The Code caps ordinary daily hours at 8 and guarantees rest after 6 consecutive normal work days; there is no counterpart to Rule XVII's 40-hour week | Arts. 83, 91 |

**The last row is the sharpest structural difference from CSC.** Government is 40 hours over 5 days by rule, so its weekly rest day never binds. A private employer may lawfully schedule six ordinary 8-hour days — 48 hours — subject to the 24-consecutive-hour rest, to any CBA or contract, and to special-sector laws. The constraint that bites is the rest period, not a weekly hour total.

## B. What counts as worked minutes — Book III Rule I §§3–6

This is khronoz's own subject matter, not payroll's, and the first draft of this file omitted it entirely.

| Rule | Text |
|---|---|
| §3, compensable hours | "All time during which an employee is required to be on duty or to be at the employer's premises or to be at a prescribed work place; and All time during which an employee is suffered or permitted to work." |
| §4, determining principle | "All hours are hours worked which the employee is required to give his employer, **regardless of whether or not such hours are spent in productive labor** or involve physical or mental exertion." |
| §5, waiting time | "Waiting time spent by an employee shall be considered as working time if waiting is an integral part of his work or the employee is required or engaged by the employer to wait." |
| §6, training attendance | Lectures, meetings and training are **not** working time only if **all three** hold: outside regular working hours, attendance in fact voluntary, and no productive work performed during it. |

Consequences: required pre-shift or post-shift activity, employer-controlled short interruptions, on-call time near the workplace, and non-voluntary training all count as worked minutes. §4 in particular means the engine must never infer "not working" from idleness — only from the absence of a requirement to be present.

## C. Flexible work arrangements

### Compressed work week, DOLE Department Advisory 02, s. 2004 ☆

- Daily hours may reach **12** without overtime premium. Work beyond **12 hours a day or 48 hours a week** is overtime.
- Requires "express and voluntary agreement by the majority of covered employees or their duly authorized representatives".
- The 60-minute meal period survives; rest days, holiday pay, rest day pay and leaves are unimpaired.
- The employer notifies the DOLE Regional Office having jurisdiction over the workplace.

**The largest design consequence in this file.** The overtime threshold is not a constant — 8 by default, 12 under a compliant CWW — **and it is two numbers, not one**: a daily threshold and a weekly ceiling of 48. A single `overtime_after` setting cannot express it. CSC's compressed week has no premium exemption either, so the daily number varies *inside* one agency as much as between agencies: it belongs to the **shift**, and the setting only seeds a new one (decision 34). The weekly ceiling has no home at all yet — `../design/06-attendance.md`, "Open before Milestone 6" item 2.

### Telecommuting — RA 11165, IRR DO 237, s. 2022

The revised Telecommuting Act IRR (DO 237-22) supersedes DO 202 s. 2019. Its timekeeping consequences:

- Work performed from an alternative workplace is compensable hours worked. Telecommuting workers are covered by regular labor standards (overtime, rest days, holidays, and night shift differential).
- **A telecommuter is not "field personnel" merely because they work remotely** — that classification applies only where actual hours cannot be determined with reasonable certainty. Where time and attendance are verifiable through computer, biometric, or electronic systems, time records must be kept and regular hours rules apply.

That second point matters directly: it forbids using remote work as a reason to stop keeping time records, which is exactly the shortcut the first draft of section G implied was available.

## D. Daily computation

### Tardiness and undertime

**There is no statutory private-sector counterpart to CSC MC 04 s. 1991 or MC 16 s. 2010.** The Labor Code creates no occurrence counting, no "habitual tardiness" threshold and no semester. A private employer's tardiness policy is its own, enforced as discipline, and the pay consequence is a deduction rather than a recorded occurrence. ("No *statutory* counterpart" rather than "no counterpart": a duty can still arise from company policy, a CBA, a contract or a sector-specific issuance.)

Consequences for the engine:

- The monthly occurrence counts `../design/06-attendance.md` rule 9 derives are **CSC-only** and must be suppressible. They are already "derived, never stored", so this is a report-level setting.
- Art. 88's no-offsetting rule is **close to** but not identical with Rule XVII §9. Art. 88 bars offsetting *undertime* against *overtime on another day*. The CSC text speaks of tardiness and absence, and CSC leave rules carry an exception the Labor Code has no counterpart to: approved compensatory service outside regular hours may offset non-attendance or undertime (`csc-rules.md` section A). So the private rule is the stricter and simpler of the two, and a shared implementation would be wrong in the government's favour.

### Absence

"No work, no pay" is the baseline; there is no leave-credit charging mechanism comparable to the Omnibus Rules on Leave. Service incentive leave is five days a year after one year of service and is the only statutory paid leave of general application — payroll and leave, out of scope.

## E. Overtime and night work

### Overtime, Art. 87, rates from the 2024 Handbook

| Overtime performed on | Additional | Compounded |
|---|---|---|
| ordinary working day | +25% of the hourly rate | 125% |
| special (non-working) day **or** scheduled rest day | +30% | 130% × 130% = 169% |
| special day that also falls on the rest day | +30% | 150% × 130% = 195% |
| regular holiday | +30% | 200% × 130% = 260% |
| regular holiday that also falls on the rest day | +30% | 200% × 130% × 130% = 338% |

Premium pay for a rest day, special day or regular holiday forms part of the regular rate when computing overtime on that day, so the multipliers compound. A CBA may stipulate higher rates.

### Night shift differential, Art. 86

"additional compensation of 10% of an employee's regular wage for each hour of work performed **between 10:00 p.m. and 6:00 a.m.** of the following day."

**The window is NOT the government's.** Private sector is **22:00–06:00** (Art. 86); general government is **18:00–06:00** (RA 11701, `csc-rules.md` section D). A first draft of this file said they were identical — they are not, and the difference is four hours a night. This is an *engine* distinction and not merely a rate one: the set of minutes classified as night work differs by regime, so a night-hours total is not portable between an agency on CSC rules and one on the Labor Code. The cross-midnight machinery in `../design/04-scheduling.md` serves both, but the boundary is a per-agency value.

Whether the differential stacks on overtime also differs. For general government it does **not** (`csc-rules.md` section D). Nothing read here settles it for the private sector, and it is payroll's question in either case — but the *minutes* on each side of 22:00 must be separable regardless.

### Night workers beyond the differential — RA 10151, DO 119-12

Under RA 10151 (Labor Code Arts. 154–161) and DO 119-12, a "night worker" is any employed person whose work requires not less than 7 consecutive hours of work between 22:00 and 06:00. Key operational rules:
- **Health assessment**: mandatory right to undergo free health assessment prior to assignment and at regular intervals.
- **Protection for pregnant and nursing workers (Art. 156)**: mandatory alternative to night work (transfer to day work or leave) before and after childbirth for a period of **at least sixteen (16) weeks**, divided between before and after delivery, or longer upon medical certification. Modeled as `Exemption` rows rather than derived from sex.

### The coverage exclusion that decides which regime an agency is in

The Handbook excludes from night shift differential **and** from holiday pay:

> "Government employees, whether employed by the National Government or any of its political subdivisions, including those employed in government-owned and/or controlled corporations **with original charters or created under special laws**"

The underlying test is constitutional and jurisprudential, not merely a Handbook exclusion. A GOCC created **by special charter** is in the civil service; a GOCC **incorporated under general corporation law** is generally under the Labor Code (*PNCC v. Sison*, G.R. 248401, 2021 ★). Two refinements the review supplied, both material:

- A special law that merely *authorizes* later SEC incorporation is **not** an original charter.
- A non-chartered GOCC can remain subject to government compensation laws even while covered by the Labor Code — so the regime is not a single switch even for one entity.

This is why a single gov/private flag cannot work, and why `../design/README.md` decision 32 makes each divergence its own setting with an onboarding preset that is never persisted.

Also excluded, and worth knowing: retail and service establishments with not more than five workers (NSD) or fewer than ten (holiday pay); kasambahay; managerial employees meeting all three tests; and field personnel and others "whose time and performance is unsupervised by the employer" — **but see section G: exclusion from these benefits does not excuse the employer from keeping a record.**

## F. Calendar events

### Regular holidays

Twelve as currently observed, per the Handbook: New Year's Day (1 Jan), Maundy Thursday and Good Friday (movable), Eidul Fitr and Eidul Adha (movable), Araw ng Kagitingan (9 Apr), Labor Day (1 May), Independence Day (12 Jun), National Heroes Day (last Monday of August), Bonifacio Day (30 Nov), Christmas Day (25 Dec), Rizal Day (30 Dec).

**The list is not Art. 94(c)'s alone** — it is the product of later holiday statutes and the President's annual proclamation, so it is data and not a constant. Art. 94(c) also treats a general election day as a holiday, but proclamations have consistently declared elections *special* non-working days instead, so election days must be classified from the applicable proclamation rather than from the Code.

| Day | Unworked | Worked, first 8 hours |
|---|---|---|
| Regular holiday | **100%**, provided the employee was present or on leave with pay on the immediately preceding work day | **200%** |
| Special (non-working) day | **"no work, no pay"** unless company policy, practice or a CBA says otherwise | 130% |
| Special day falling on the rest day | no work, no pay | 150% |
| Regular holiday falling on the rest day | 100% | 200% × 130% |
| Scheduled rest day | — | +30% of the regular wage (Art. 93) |

Four engine consequences:

- The conditional on unworked regular holiday pay — presence or paid leave on the immediately preceding work day — is **a rule khronoz can answer and payroll cannot**, because it reads the previous workday's status. Milestone 6.
- **Successive regular holidays (Book III, Rule IV §10)**: Where there are two (2) successive regular holidays (e.g., Maundy Thursday and Good Friday), an employee who absents himself without pay on the workday immediately preceding the first holiday forfeits pay for *both* holidays, *unless* he works on the first holiday, in which case he is entitled to regular holiday pay on the second holiday. The deriver must therefore inspect attendance across the full multi-day sequence rather than only a single preceding day.
- **Cross-midnight holiday attribution**: Statutory holiday and rest day premiums attach to the hours **actually rendered on the calendar day of the holiday** (from 00:00 to 24:00). An overnight shift crossing midnight into or out of a holiday must have its holiday-rate minutes attributed to the actual calendar day of occurrence, derivable from `actual_at`.
- **Coincident holidays are their own classifications.** Double-regular and double-special days occur (a movable Islamic holiday landing on a fixed regular holiday, for instance). Peso computation is out of scope, but the calendar must preserve the *overlap* rather than collapsing it, because the classification of the minutes changes. `holidays` has `UNIQUE (agency_id, date, name)`, so two rows on one date are already representable — what must not happen is a resolver that takes the first match.

### Suspension of work ★

There is no statutory private-sector counterpart to EO 66 s. 2012 or to the Omnibus Rules on Leave §32 charging rule. When a typhoon or a local announcement closes a private workplace, the default is "no work, no pay" for hours not worked, subject to company policy or CBA. So the CSC rule that charges the absent portion from shift start to `declared_at` is **CSC-only** and must be suppressible.

## G. Recording attendance — Book III, Rule X

**The first draft of this file said "no prescribed form" and implied field personnel need no record. Both were wrong.** There is no prescribed *layout*, but the content, the means and the retention are all prescribed.

| Requirement | Text |
|---|---|
| Individual daily time record | **Required**, by one of three means: a bundy clock on which the employee punches an individual card; a timekeeper who times every employee in and out in a record book; or "furnishing the employees individually with a daily time record form in which they can note the time of their respective arrival and departure from work" |
| Payrolls | must show rate of pay, amount due for regular work, amount due for overtime, deductions, and amount actually paid |
| Signature or thumbmark | §7 requires the individual time record itself to bear "the signature or thumbmark of the employee concerned **for each daily entry therein**"; §6 separately requires one on the payroll, "at the end of the line opposite his name". An earlier draft of this file said payroll *only*, which is wrong. **Whether §7 binds a bundy-clock or biometric employer is open** — item 4 |
| Retention | "preserved for at least **three (3) years** from the date of the last entry" |
| Custody | kept on file in chronological order in or about the premises where the employee is employed, "open to inspection and verification by the Department of Labor and Employment" |
| The exception | "Managerial employees, officers or members of the managerial staff, as well as non-agricultural field personnel, need not be required to keep individual time records, **provided that a record of their daily attendance is kept and maintained by the employer**" |

Four things this changes:

1. **`employees.exempt` does not mean "no record".** It means no *individual* time record; a daily attendance record is still owed. The flag's meaning in `../design/01-organization.md` should be read as "no DTR expected", never "outside the system".
2. **khronoz has no retention policy and now needs one.** Three years from the last entry is a product requirement, and it interacts with `RemoveEmployee`'s soft delete and with whatever the eventual purge story is. Taken up in `privacy-rules.md`, which pins the rule to **Rule X §12** and supplies the other half — the Data Privacy Act ceiling, the deletion mechanism, and why the retention floor and the ceiling do not actually conflict.
3. The private DTR template is free in *layout* only. It must carry arrival and departure times per day and be printable for an inspection.
4. **Whether the private time record needs a *per-day* signature is open, and it decides a schema grain.** §7's words are unqualified — "for each daily entry therein" — and sit ahead of all three methods, which reads as a signature per day and would make the monthly `UNIQUE (ledger_id, role)` grain on `attestations` (`../design/07-constraints.md`) insufficient for a private agency. Against that reading, three arguments: **§8** requires "entries in time books and daily time records" to be "accomplished in ink" while naming "filled-up bundy clock cards" separately, in its *filing* clause only — so a punched card is textually a different artifact from an ink-written record; requiring a handwritten signature on every punch would **collapse §7's method (a)**, the bundy clock, into method (c), the employee-completed form, leaving (a) with no distinct content; and **RA 8792** treats an authenticated electronic entry as satisfying a statutory signature requirement, which a biometric or RFID event enrolled to one employee arguably is. Two adversarial reviewers split on this — one called the monthly grain a real defect, the other called the per-day reading an overreading — and **neither reached a BWC opinion or an enforcement issuance settling it**. §8's text above was read in primary text; the claim about what inspectors actually accept was not. **Do not change the schema until this is settled**; decision 34 records it as open.

Overtime for a private employer is authorised by the employer rather than by a written authority with a DBM circular behind it, so the overtime-authority row JC 2 s. 2015 requires is optional here.

## H. Global defaults versus agency settings

Each difference is a key in `agencies.settings` with the code default shown. See `../design/README.md` decision 32.

| Setting | CSC | Labor Code | Read by |
|---|---|---|---|
| `occurrences` | `true` | `false` | M6, the ledger's derived counts |
| `suspension_charge` | `true` | `false` | M6 |
| `dtr_template` | `"form48"` | `"plain"` | M7 |
| `rest_day_after` | `null` | `6` | **M3**, schedule validation |
| `overtime_after` | the prescribed shift length, 8 by default | 8, or **12** under a compliant CWW | M6 |
| `overtime_after_weekly` | none | **48** | M6 |
| `night_from` | `18:00` | `22:00` | M6, frozen into the workday snapshot — decision 33 |
| `retention_years` | **5** (or 1 yr post-COA audit) | **3** | M8 |

**`overtime_after` is a fallback, not the authority.** A flat agency-level 8 is wrong the moment a shift is longer than eight hours by design: under Res. 2600838 a CSC agency on a compressed week works a 10-hour day, and a hospital shift runs 12, and JC 2 s. 2015 §8.2.2 starts overtime after the *prescribed* hours rather than after eight. An engine reading only the agency setting would manufacture two hours of overtime on every ordinary CWW day. The prescribed length lives in the resolved shift, which `06-attendance.md` already freezes into the workday snapshot, so the setting is the default a shift inherits and the snapshot is what the deriver reads. Raised by the third-lineage review.

`rest_day_after` can be bounded — `NULL OR BETWEEN 1 AND 6`, so an agency may be stricter than statute (a CBA might) but never looser. `null` must stay legal because a CSC agency is genuinely not under Art. 91, and since the regime itself is deliberately not stored, no constraint can tell a lawful CSC null from a private employer nulling it to escape. **khronoz enforces the rule an agency configures; it does not adjudicate which law binds them.**

**But `rest_day_after` alone cannot enforce Art. 91.** The article protects a *continuous* 24-hour period, and counting six non-Off turns does not measure it: a night turn ending 06:00 followed by an Off day and a turn starting 22:00 the next day yields an Off *turn* but not 24 continuous hours free of work. A correct validator measures elapsed no-work time across turn boundaries, which for cross-midnight and split shifts is arithmetic on the resolved slots rather than a count of positions. This is a Milestone 3 design item, not a Milestone 3 constraint one-liner.

## I. What this changes in the design

| # | Change | Where |
|---|---|---|
| 1 | `settings.rest_day_after`, plus a validator measuring **elapsed continuous no-work hours**, not a turn count | M3, `04-scheduling.md` and `07-constraints.md` |
| 2 | `settings.overtime_after` **and** `settings.overtime_after_weekly` — the CWW threshold is two numbers | M6 |
| 3 | `settings.occurrences` and `settings.suspension_charge`, suppressing the CSC-only derivations | M6, `06-attendance.md` rule 9 |
| 4 | `settings.dtr_template`, a second plain monthly template carrying arrival and departure per day | M7 |
| 5 | The unworked-regular-holiday condition reads the **previous** workday's status — attendance data, not payroll | M6 |
| 6 | Coincident holidays must not collapse: the resolver reads all rows for a date, never the first | M4 |
| 7 | **Record retention: 3 years from the last entry** (Labor Code) / **5 years or until COA audit clearance** (CSC), and a purge story that respects it | M8, and it constrains `RemoveEmployee` |
| 8 | `employees.exempt` means "no individual time record", not "no record" — a daily attendance record is still owed | wording in `01-organization.md` |
| 9 | Hours-worked doctrine (section B): required pre/post-shift activity, waiting time, on-call, and non-voluntary training are worked minutes — on-call only when mobility is restricted, Rule I §5(b) | M6's deriver |
| 10 | **Open, not settled:** whether Rule X §7's "signature or thumbmark … for each daily entry" binds a bundy-clock or biometric employer. If it does, the private template and `attestations`' monthly grain both need a per-day signature; §8 and RA 8792 argue it does not. Section G item 4 carries both readings | decide before M7's template; **no schema change meanwhile** |
| 11 | `overtime_after` is the default a shift inherits, never what the deriver reads — the prescribed shift length in the workday snapshot is | M6 |
| 12 | **Successive regular holidays (Book III Rule IV §10):** multi-day attendance evaluation across consecutive holidays | M6 |
| 13 | **Cross-midnight holiday attribution:** statutory holiday rates attach to calendar day (00:00–24:00) hours rendered, derived from `actual_at` | M6 |

## Sources

- Labor Code (PD 442), Arts. 83–94: https://lawphil.net/statutes/presdecs/pd1974/pd_442_1974.html
- DOLE **Handbook on Workers' Statutory Monetary Benefits, 2024 Edition** (read as text): https://bwc.dole.gov.ph/wp-content/uploads/2024/10/Workers-Statutory-Monetary-Benefits-Handbook-2024-Edition.pdf — mirror actually fetched: https://www.alburolaw.com/wp-content/uploads/2025/07/2024-Workers-Statutory-Monetary-Benefits-Handbook.pdf
- Omnibus Rules Implementing the Labor Code, Book III, **Rule I §§3–6** and **Rule X** (read): https://library.laborlaw.ph/omnibus-rules-labor-code-book-3/ — **Rule X §§6–7 and Rule I §5(b) re-read verbatim from this copy on 2026-09-10.** `chanrobles.com` and the ILO NATLEX PDF both return 403; this mirror is the one that answers.
- DOLE Department Advisory 02 s. 2004, compressed workweek ☆: https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/11/40740 (refused fetch), https://dole.gov.ph/news/department-advisory-no-02-04-implementation-of-compressed-workweek-schemes/ (403), summary at https://jur.ph/law/summary/implementation-of-compressed-workweek-schemes
- DOLE holiday pay rules ☆: https://dole.gov.ph/news/dole-issues-pay-rules-for-regular-holidays-special-non-working-days-in-2024/
- RA 11165 Telecommuting Act: https://lawphil.net/statutes/repacts/ra2018/ra_11165_2018.html; DO 237 s. 2022 ★: https://dole.gov.ph/news/dole-expands-wfh-assures-standards-in-alternate-workplace/
- RA 10151, night workers ★: https://lawphil.net/statutes/repacts/ra2011/ra_10151_2011.html
- *PNCC v. Sison*, G.R. 248401 (2021), on the original-charter test ★: https://lawphil.net/judjuris/juri2021/jun2021/gr_248401_2021.html
- DOLE Department Advisory 01 s. 2015, renumbering: https://batasnatin.com/compare/labor-code-original-vs-renumbered — **note:** Arts. 83–94 keep their numbers under the renumbered edition, and DOLE's own 2024 Handbook still cites them, so this file uses original numbering. Confirmed by the cross-review.

## Gaps resolved by 2026-09-10/11 primary-text verification

1. **DO 237 s. 2022 (Telecommuting Act IRR)**: Resolved. Work from an alternative workplace is compensable hours worked under regular labor standards. Telecommuters whose hours are verifiable are not exempt "field personnel", so individual time records must be maintained.
2. **RA 10151 / DO 119-12 (Night Workers)**: Resolved. Night workers (performing at least 7 consecutive hours between 22:00 and 06:00) are entitled to free health assessments. Pregnant and nursing employees are entitled to mandatory transfer to day work during a **16-week window** (divided before and after childbirth) or longer if medically certified. Modeled via `Exemption`.
3. **DA 02-04 (Compressed Workweek)**: Confirmed. Normal daily hours may extend up to 12 hours without overtime pay provided the 48-hour weekly ceiling is not exceeded. Work beyond 12 hours/day or 48 hours/week is overtime. 60-minute meal break preserved.
4. **Cross-midnight premium attribution**: Resolved. Under DOLE labor standards, statutory holiday and rest day premiums attach to hours actually rendered on the calendar day of the holiday (00:00–24:00). Overnight shifts crossing into or out of a holiday must have holiday minutes attributed to the actual calendar day of occurrence, derived from `actual_at`.
5. **Successive regular holidays**: **Verified in primary text — Book III, Rule IV, Section 10.** An employee absent without pay on the workday immediately preceding the first holiday loses pay for *both* holidays, *unless* they work on the first holiday, in which case they receive holiday pay on the second holiday. The deriver must evaluate the multi-day holiday sequence.
6. **Government DTR retention**: Settled. Under NAP General Records Disposition Schedule Item 44 and COA audit rules, CS Form 48 DTRs are retained for 1 year after post-audit and settlement of accounts by COA; standard agency practice retains them for **5 years** or until COA clearance.

## Remaining open research items

7. Sector-specific hour laws (health workers under RA 7305 have a CSC counterpart; private hospitals, security agencies and BPO night work may carry their own issuances) remain unexamined.
8. Whether any of the above moved after 2024 should continue to be monitored (the CSC side moved three times between 2022 and 2026).
