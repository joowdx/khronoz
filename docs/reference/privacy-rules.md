# 10 — data privacy rules that touch retention

Written 2026-09-11. **Verified in primary text**: RA 10173 (Data Privacy Act of 2012) §§3(h), 3(i), 3(l), 4, 11, 12, 13, 14, 16, 21; Omnibus Rules Implementing the Labor Code, Book III, Rule X §§7, 8, 9, 11, 12.

Marks: **★** not read in primary text by anyone; **☆** not read by the author but attested by a secondary source that quotes the instrument closely. `privacy.gov.ph`, `officialgazette.gov.ph` and the Supreme Court e-library all refused fetches for the IRR and the NPC advisories, so the IRR §19 wording below is ☆ and the NPC advisories are ★. Nothing in this file is implemented.

Cross-reviewed 2026-09-11 by two independent reviewers: one adversarial, given a deliberately naive strawman to break (section H), and one legal-research pass asked to verify each proposition in primary text. Where a reviewer and the statutory wording agree independently, that is noted, because convergence from two directions is the strongest signal in this file. Where they split, both readings are recorded.

**One reviewer citation was fabricated and is recorded here so it is not repeated.** The legal pass supplied a verbatim "PD 1445 §43, *Disposition of Old Records*" containing a ten-year voucher rule and a litigation-hold clause, marked primary text at high confidence. PD 1445 §43 is "Powers, functions, and duties of auditors as representatives of the Commission"; the quoted section does not exist anywhere in the decree, whose full 131 sections were extracted and searched. The ten-year figure is real and lives in **§26** (section G); the litigation-hold limb is invented. Treat every ★ advisory-opinion number in this file as unverified for the same reason.

Companion to `csc-rules.md` and `dole-rules.md`. **Those two set retention floors. This file sets the ceiling and the deletion mechanism.** Reference files are read, not designed in — sections H to J describe the problem and list deltas without deciding them.

## A. The headline: the DPA does not fight CSC and DOLE retention, it incorporates them

The naive reading is that privacy law and records law pull in opposite directions and khronoz has to pick. That reading is wrong, and it is wrong because of one clause.

| Force | Instrument | Says | Direction |
| --- | --- | --- | --- |
| DOLE floor | Omnibus Rules Book III **Rule X §12** | employment records "shall be preserved for at least three (3) years from the date of the last entry in the records" | minimum |
| CSC floor | NAP GRDS Item 44 + COA ★ | ≥ 1 year after post-audit and settlement of accounts; 5 years in agency practice | minimum, **event-based** |
| DPA ceiling | RA 10173 **§11** | "Retained only for as long as necessary for the fulfillment of the purposes for which the data was obtained **or** for the establishment, exercise or defense of legal claims, **or** for legitimate business purposes, **or as provided by law**" | maximum, **with a carve-in** |
| DPA disposal duty | IRR **§19** ☆ | "Personal data shall not be retained longer than necessary. Retention of personal data shall be allowed in cases provided by law." Disposal must be "in a secure manner that would prevent further processing, unauthorized access, or disclosure" | maximum |

The last clause of §11 — **"or as provided by law"** — is the whole answer to the first question. A statutory retention period is not an exception the DPA tolerates; it is one of the four purposes the DPA itself names as sufficient. While Rule X §12's three years are running, the DTR is retained *for a §11 purpose*, and the ceiling is not even engaged. The floor sits inside the ceiling by the ceiling's own terms.

§11 has a second limb that matters as much. **§11(f)** requires personal information to be "[k]ept in a form which permits identification of data subjects for no longer than is necessary for the purposes for which the data were collected and processed", then adds two provisos: data collected for other purposes "may be processed for historical, statistical or scientific purposes", and "in cases laid down in law may be stored for longer periods", provided "adequate safeguards are guaranteed by said laws authorizing their processing". So §11(f) is the statutory basis on which a de-identified statistic may outlive the record it came from — and, read with §11(e), it says the thing to remove at end of life is *identifiability*, not necessarily the row.

**So the conflict is not at the head. It is at the tail.** The day the floor expires and no legal-claims basis survives, "as provided by law" stops supplying a purpose, nothing else in §11 supplies one for a fourteen-year-old punch record, and IRR §19's disposal duty engages. That is where khronoz is currently non-compliant, and it is non-compliant *by design*:

> `03-terminals.md` rule 1: "A timelog is immutable. Bad timelogs get `voided_at` and `reason`. **Nothing is ever pruned.**" Enforced by `REVOKE DELETE ON timelogs FROM chronoz` (`07-constraints.md`).

As an unqualified invariant that is unlawful. As a scoped one — nothing is pruned *while the retention obligation runs* — it is exactly right, and it is the same immutability the audit trail needs. The invariant does not need to be abandoned, it needs a terminus.

## B. Retention is a duty in both directions

Two obligations, not one, and khronoz currently implements only the first:

| Obligation | Source | khronoz today |
| --- | --- | --- |
| Do not destroy before the floor | Rule X §12; NAP GRDS 44 | satisfied, and over-satisfied — `REVOKE DELETE` makes early destruction impossible |
| **Do destroy after it** | RA 10173 §11; IRR §19 ☆ | **no mechanism exists** |
| Dispose so as to "prevent further processing" | IRR §19 ☆ | n/a — a soft delete or a `voided_at` is not disposal |
| Disclose the retention period *before* collecting | RA 10173 **§16(b)** | **no mechanism exists** — see section C |

§16(b) is the quiet one. It entitles the data subject to be furnished, *before* their data enters the processing system, a defined list that includes the period for which the information will be stored. An agency that enrols a fingerprint without telling the employee how long the record is kept has already breached §16(b), independently of anything about deletion.

## C. The right to erasure is conditional — and "blocking" is one of its own remedies

This is the answer to the third question. RA 10173 §16(e) is **not** a GDPR Art. 17 right to be forgotten. The right and its trigger are one sentence:

> "Suspend, withdraw or order the blocking, removal or destruction of his or her personal information from the personal information controller's filing system **upon discovery and substantial proof that** the personal information are incomplete, outdated, false, unlawfully obtained, used for unauthorized purposes **or are no longer necessary for the purposes for which they were collected**."

Two consequences.

**1. The burden is the data subject's, and the grounds are closed.** "Discovery and substantial proof" of one of six listed grounds. A bare "delete my attendance records" pleads none of them. Test each ground against a lawfully collected in-window DTR:

| Ground | Bites an in-window DTR? | Why |
| --- | --- | --- |
| incomplete | no | and if it did, the remedy is completion |
| outdated | no | a DTR is a dated historical fact; age is its point |
| false | **yes, if the punch is wrong** | but see §16(d) — the remedy is correction |
| unlawfully obtained | **yes, if enrolment was unlawful** | e.g. biometrics taken with no basis and no alternative offered — section E |
| used for unauthorized purposes | **yes, as to the use** | kills the *use*, not the record |
| no longer necessary for the purposes collected | **no, while the floor runs** | §11 makes it necessary "as provided by law" |

So inside the retention window a well-founded §16(e) request is almost always a request about **accuracy or use**, not about existence — and khronoz already answers both correctly. `voided_at` + `reason` on an immutable row is the §16(d) correction remedy ("dispute the inaccuracy or error ... and have the personal information controller correct it immediately"), and it is a *better* answer than deletion, because a deleted punch cannot show that the correction happened.

**2. "Blocking" is in the statute, not a workaround.** §16(e) lists five remedies — suspend, withdraw, block, remove, destroy — and the controller may satisfy a valid request with the least destructive one that answers the proven ground. Where the ground is proven but the floor still runs, **blocking is the only remedy that satisfies both laws at once.** That is a textual result, not a compromise: destruction would breach Rule X §12, and refusal would breach §16(e).

The honest shape of the answer to "can I have my data deleted?":

| When | Answer | Basis |
| --- | --- | --- |
| Inside the floor, no ground proven | No, with a written, dated, cited refusal | §16(e) preconditions unmet; §11 "as provided by law"; §12(c) legal obligation |
| Inside the floor, accuracy ground proven | Correct it, do not delete | §16(d); void + reason |
| Inside the floor, use or unlawful-collection ground proven | **Block** — stop processing, keep the row | §16(e) names blocking |
| Floor expired | **Destroy — and not only on request** | §11, IRR §19; the duty is the controller's, not the subject's |

The last row is the one that matters most and the one nobody asks for. After the floor, disposal is not a favour granted to a data subject who complains. It is owed to every employee who never asked.

**The regulator has said this directly.** NPC Advisory No. 2021-01 (*Data Subjects Rights*, 29 January 2021) ☆ addresses the erasure and blocking right specifically, and holds that a controller **may deny** a request, wholly or partly, where the data remains necessary for fulfilment of the purpose it was obtained for, **compliance with a legal obligation**, the establishment, exercise or defence of a legal claim, or the controller's legitimate business purposes. It also requires controllers to run "a clear, simple, straight-forward and convenient procedure" for exercising rights, with request forms and identity verification — so the *procedure* is an obligation even where the *answer* is no.

Two things in it bear directly on the design. First, it confirms a partial denial is the expected shape, which is why the answer must be per record class. Second — and this closes a gap the adversarial review raised — it states the right reaches personal data "in both live and **back-up** systems". Backup retention is therefore not a footnote to disposal; the regulator puts it inside the obligation.

**The answer is per record class, not per request.** One §16(e) letter can touch a 2019 DTR that is out of window, a 2026 DTR that is in it, a biometric enrolment whose lawfulness is contestable, and an attestation that is somebody else's signature. A single yes or no to the whole letter is wrong in both directions.

## D. §4(a) does not exempt a government DTR

Worth testing, because if it did, half of this file would be private-sector-only. It does not.

§4(a) removes from the Act's scope "[i]nformation about any individual who is or was an officer or employee of a government institution **that relates to the position or functions of the individual**", enumerating four things: (1) the fact of employment, (2) title, business address and office telephone number, (3) "the classification, salary range and responsibilities of the position held", and (4) "the name of the individual on a document prepared by the individual in the course of employment".

Three reasons a DTR falls outside the carve-out:

1. **The limiter is "the position or functions", and attendance is neither.** Every enumerated item describes the *post* — that it exists, what it is called, what it pays, what it is responsible for. Whether *this person* arrived at 08:47 on a Tuesday describes the person's conduct in the post, not the post. Sub-item (4) reaches "the **name** of the individual on a document prepared by the individual" — on the DTR that exempts the name in the header, and says nothing about the minutes in the body.
2. **§4 exempts information, not entities, and the NPC construes it that way.** NPC Advisory No. 2022-01 (Request for Personal Data of Public Officers) ★ states that public officers are data subjects "within the purview of the Act, with all the concomitant rights and available redresses under the same", with only *certain* data relating to their positions and functions excepted. There is no reading on which an agency becomes un-covered.
3. **The proviso survives anyway.** §4 closes "Provided, That the requirements of Section 5 are complied with" — so even squarely exempt information keeps obligations attached.

**Practical effect: none of the exemptions help.** A biometric enrolment, a punch time, a tardiness occurrence and a night-differential minute count are all ordinary personal information of an ordinary data subject, whether the employer is a national agency or a private hospital. `settings.retention_years` differing by regime is a *floor* difference; the DPA ceiling is identical for both tenants.

**The two reviewers split on this, and the split is worth keeping.** The adversarial reviewer agreed with the conclusion above outright: §4(a) "does not categorically exclude a DTR's biometric UID/template, raw arrival/departure timestamps, absences, leave/exemption information, disciplinary implications, or device logs." The legal pass proposed a softer *bifurcated* reading ★ — that the certified monthly CSC Form 48, as an official record of hours worked discharged in public office, sits largely within §4(a)'s transparency policy, while the processing system, the biometric identifiers and the raw punch logs remain fully governed.

Both readings converge on the only thing the design needs: **the raw timelogs, the enrolments and the derived workdays are covered.** They differ only about the certified monthly artefact, which is the one thing an agency publishes anyway. Nothing in the retention design turns on resolving it, so it stays open rather than being decided here.

No NPC opinion was found ruling squarely on whether a DTR sits inside §4(a) — the bifurcated reading rests on advisory-opinion numbers this author could not reach, and the header's fabrication note applies.

## E. Biometrics are not statutorily sensitive personal information

A surprise, and it cuts against the intuitive design instinct.

**§3(l)'s enumeration does not contain the word "biometric" or "biometrics".** The four categories are: race/ethnicity/marital status/age/colour/religion/philosophy/politics; health, education, genetic or sexual life, or any offence proceeding; identifiers issued by government agencies peculiar to an individual (social security numbers, health records, licences, tax returns); and anything classified by executive order or act of Congress.

A fingerprint template is therefore **personal** information, not **sensitive** personal information, on the statute's text. Consequences:

| | Consequence |
| --- | --- |
| Lawful basis | §12 applies, **not** §13. So §12(c) (legal obligation) and §12(e) (functions of public authority) are available to an agency, and §12(f) (legitimate interests) to a private employer. Consent is *not* required, which is fortunate — employment consent is rarely freely given |
| But not a free pass | NPC treats biometrics as high-risk and applies the proportionality principle: data must be "adequate, relevant, suitable, necessary, and not excessive", and processed "only if the purpose ... could not reasonably be fulfilled by other means" ★ |
| The proportionality problem | **Rule X §7 gives three lawful methods and only one is biometric** — a bundy clock with individual cards, a timekeeper's record book, or a form the employee fills in. Because the law itself supplies non-biometric means, an employer cannot argue the purpose "could not reasonably be fulfilled by other means". A biometric-only system with no alternative is the weakest point in the whole stack |
| Where khronoz already answers this | `Timelog.source = 'manual'` with a required `user_id` (`03-terminals.md` rule 4) is exactly Rule X §7 method (b) — a timekeeper's entry, attributed. It exists for a different reason and happens to be the privacy-compliant alternative. It should be recognised as that, not left incidental |

The proportionality rule in the IRR's own words, §18(c) ☆: processing "shall be adequate, relevant, suitable, necessary, and not excessive in relation to a declared and specified purpose", and "[p]ersonal data shall be processed only if the purpose of the processing could not reasonably be fulfilled by other means". Rule X §7's three methods are exactly such other means.

**Biometrics change the breach calculus even though they are not sensitive personal information.** NPC Circular No. 16-03 (*Personal Data Breach Management*) ☆ makes notification of the Commission and affected data subjects mandatory within **72 hours** where three conditions all hold: the data is sensitive personal information *or other information usable to enable identity fraud*, there is reasonable belief of unauthorised acquisition, and there is a real risk of serious harm. The first condition's illustrative list **names biometric data**. So holding enrolments or templates converts a leak that might otherwise fall below the threshold into a mandatory 72-hour notification — and because a fingerprint cannot be reissued like a password, the harm limb is hard to argue away. This is a §3(l)-independent reason to hold as little biometric data as the purpose allows.

A 2026 NPC circular reportedly replaced the blanket Privacy Impact Assessment rule with risk-based triggers that name biometric data as high-risk processing ★ — not read, flagged in section J.

## F. Controller and processor: which one is khronoz

This decides *who answers the employee*, and it is a liability boundary in the same way decision 32's was.

| Role | §3 definition (verbatim) |
| --- | --- |
| Personal information controller, §3(h) | "A person or organization who **controls** the collection, holding, processing or use of personal information, including a person or organization who **instructs another** person or organization to collect, hold, process, use, transfer or disclose personal information on his or her behalf" |
| Personal information processor, §3(i) | "Any natural or juridical person qualified to act as such under this Act **to whom a personal information controller may outsource** the processing of personal data pertaining to a data subject" |

On a hosted, multi-tenant khronoz: **each agency is the controller; khronoz is the processor.** The agency decides who is enrolled, what the retention setting is, and whether a §16(e) request is well-founded. khronoz holds and computes on instruction.

**§3(h) settles it by its own terms, not by inference.** The definition ends with an exclusion the tables above omit: "The term excludes: (1) A person or organization who performs such functions **as instructed by another** person or organization; and (2) An individual who collects, holds, processes or uses personal information in connection with the individual's personal, family or household affairs." A platform processing attendance on an agency's instruction is textually excluded from being the controller of it.

That allocation is load-bearing:

- **§21 leaves accountability with the agency** — "responsible for personal information under its control or custody, **including information that have been transferred to a third party for processing**", and it must use contractual means giving comparable protection. So each tenant needs a data processing agreement, and the platform cannot quietly become the decision-maker.
- **§14 binds khronoz directly anyway** — "The personal information processor shall comply with all the requirements of this Act and other applicable laws." Being a processor is not a shelter.
- **The product requirement follows exactly**: khronoz must be *able to execute* a lawful blocking or disposal **on an agency's instruction**, per employee, per period — and must not decide erasure itself. A platform-level purge button that a platform user can press across tenants would put khronoz in the controller's chair for someone else's employees.

- **The outsourcing agreement has prescribed contents** — IRR Rule X §§43–44 ☆ require the processor to process only on the controller's documented instructions, bind its personnel to confidentiality, implement §20 security measures, **assist the controller by appropriate technical means in responding to data-subject rights requests**, assist with breach reporting and impact assessments, submit to audit, and at the controller's choice delete or return all personal data on termination unless law requires retention. The fourth of those is a product requirement, not a contract clause: khronoz has to *have the mechanism* for an agency to execute a blocking or a disposal.

This mirrors decision 32's boundary: khronoz enforces the retention an agency configures; it does not adjudicate what that agency's retention obligation is.

## G. The floors, with citations pinned

`dole-rules.md` cites "Rule X §§6–12" as a range. Pinned:

| Rule X § | Content |
| --- | --- |
| §7 | individual time record required, "bearing the signature or thumbmark of the employee concerned for each daily entry therein", by any of three methods |
| §8 | entries "accomplished in ink"; filled-up bundy clock cards, timekeeper's books and DTR forms "kept on file in chronological order" |
| §9 | the exception — managerial employees, officers or members of the managerial staff, and non-agricultural field personnel need not keep *individual* time records, "provided that a record of their daily attendance is kept and maintained by the employer" |
| §11 | place — "kept and maintained by the employer in or about the premises of the work place" |
| **§12** | **"All employment records required to be kept and maintained by employers shall be preserved for at least three (3) years from the date of the last entry in the records."** |

Two things §12's words decide that a design might get wrong:

- **"at least"** — three years is a floor with no statutory ceiling of its own. The ceiling comes from the DPA, not from here.
- **"the last entry in the records"** — the clock attaches to *the records*, plural, not to a single document. Two readings are defensible in the words: a per-document one, on which a 2019 DTR of a still-serving employee is already out of window while their 2026 DTR is not; and a one-body one, on which the whole set shares a clock running from its most recent entry. §11's "[a]ll employment records of the employees ... kept and maintained ... in or about the premises" custody framing points at a body rather than a document.

**Settled 2026-09-11: the record series is *all attendance records*.** Timelogs, punches, workdays, ledgers and attestations form one series under one schedule — not separate retention classes — and the clock runs from the last entry in that series for the employee. Consequences in section H item 1.

### The government floor is a stack, not a figure

| Instrument | Says | Mark |
| --- | --- | --- |
| NAP GRDS ★ | DTR retained "1 year after data has been posted in the leave cards and post-audited", disposable after post-audit and settlement by COA | ★ — item number unconfirmed, see section J |
| Agency practice ★ | 5 years, or until audit clearance | informal; **not in the schedule's text** |
| **PD 1445 §26** | COA's authority comprehends "the keeping of the general accounts of the Government, **the preservation of vouchers pertaining thereto for a period of ten years**" | **primary, verified** |
| PD 1445 §43(4) | auditors have custody of "paid expense vouchers, journal vouchers, ... and similar documents **together with their respective supporting papers**" | **primary, verified** |

The ten-year figure matters because a DTR is a *supporting paper* to a payroll disbursement voucher. Whether that makes the DTR itself subject to §26's ten years is **not settled**: §26 speaks of vouchers held in COA's custody under §43(4), and the copy living in an agency's HR system — which is what khronoz holds — is a different artefact from the one submitted to the resident auditor. Both reviewers flagged this same divergence, and neither resolved it.

**The design consequence stands regardless of the resolution:** the government floor is the maximum of several instruments, at least one of which is event-based and one of which may be ten years. `retention_years = 5` does not express it, and cannot.

### Why three years, in the private sector

Labor Code **Art. 306** (formerly Art. 291) ☆ prescribes that money claims arising from employer-employee relations prescribe in **three years** from accrual. Rule X §12's three years is that window, which supports the per-record reading above: wage, overtime and holiday claims accrue per pay period, so a three-year clock from a given period's cutoff covers exactly the span in which that period's record can be put in issue. That is a *reason* for the record-set grain, not merely a preference for it.

`Rule X §11` (place of records) and RA 10173 have a quiet interaction worth noting: a cloud-hosted DTR is not "in or about the premises of the work place" in any literal sense. Whether an inspectable printable satisfies §11 is untested ★.

## H. Where a naive purge design breaks

An adversarial review on 2026-09-11 was given a strawman — a per-employee retention clock driven by `settings.retention_years`, a mandatory nightly purge run by a second Postgres role, blocking via an `Employee.restricted_at` flag, and a permanent `purges` tombstone — and asked to destroy it. It did. Its verdict was **wrong in kind, not merely defective**: retention has to be decided **per record package and per legal event**, never per employee and never by one integer. That conclusion arrives independently at the same place as Rule X §12's "the last entry in **the records**" (section G), which is the strongest signal in this file — statutory wording and schema analysis converging from opposite directions.

The findings below are properties of the problem, verified against `07-constraints.md`. They are recorded here so a future design does not rediscover them.

### 1. One series per employee means an active employee's records never age out — accepted, with a residual risk

The reviewer's sharpest objection was aimed at exactly this grain: employee 104's deployment ends in 2019, they are rehired under the same employee number in 2023 (decision 28 makes this one employee with two spells), and they punch through 2028, so a clock keyed to the series' last entry holds the 2019 records until 2031 — nine years past that document's own last entry.

**That consequence is accepted** (section G). The series is all attendance records, so there is one clock per employee and a new spell's entries carry the earlier spell's records with them.

| Case | When the series becomes disposable |
| --- | --- |
| Active employee | never, while they keep punching — the series always has a current last entry |
| Separated employee | last entry + floor, plus any hold |
| Rehired after a gap | the new spell's entries reset the whole series' clock; the earlier spell is not disposed of separately |
| Never punched | no attendance entry exists, so the series has no last entry — needs its own rule, item 7 |

The gain is worth naming, because it removes machinery rather than adding it: **disposal keys on separation, not on a rolling monthly sweep.** One clock per employee, evaluated when employment ends, is far less apparatus than a per-package schedule — and it dissolves the attested-ledger problem in item 3 outright, because a locked and attested month is never disposed of while any later month of the same employee survives.

**The residual risk is proportionality, not the floor.** Holding a thirty-year employee's 1996 punches clears Rule X §12 comfortably and is the weakest point against §11(e)'s "only for as long as necessary" and IRR §18(c)'s "not excessive". The defence is that the employment relationship subsists and with it the purpose; the counter is that a 1996 punch serves no live purpose in 2026 and Art. 306 prescribed any claim on it in 1999. **Nothing about this grain requires the series to be unbounded** — an agency may still adopt a policy disposing of a serving employee's oldest records, and the design should not make that impossible. Recorded as a live consideration, not a defect.

### 2. "Failing to purge is itself a violation" is overstated

The strawman made a missed nightly `DELETE` an alertable compliance failure. It is not. §11 names "the establishment, exercise or defense of legal claims" as a purpose in its own right, so retention past the floor is lawful whenever a claim basis exists. What is owed is an **eligibility review**, not a `DELETE`. Eligibility is:

```
max(statutory or event floor, hold release, any other declared purpose)
```

A purge that ignores a hold is a worse failure than a purge that did not run.

### 3. Destroying workdays falsifies a signed attestation

September 2022 is locked and attested by employee, supervisor and unit head. The ledger's frozen calculation and each completed rendition preserve the subject matter those application attestations certify; the attestations, ledger, and rendition therefore form one evidential package. A future certificate-backed signature adds exact signed document bytes to that same package rather than replacing it.

**An attestation, its frozen rendition, and any retained canonical PDF are themselves evidential personal data.** `06-attendance.md` makes attestations require a locked ledger. M7 always freezes the completed rendition and enables ledger verification independently of storage; agency PDF archiving is opt-in and defaults off. With archiving off, downloaded PDF bytes are transient and discarded. With archiving on, retained bytes and their generic document/location records belong to the attendance series. A future cryptographic signature adds append-only signed revisions and public validation material to that package. Those retained records cannot be destroyed underneath the subject matter they prove; all follow the attendance-record series and its holds.

### 4. The FK graph fixes the delete order, and it is not negotiable

For the attendance package, verified against `07-constraints.md`:

| Order | Delete | Blocked otherwise by |
| --- | --- | --- |
| 1 | stored objects, then `locations` | a location is the inventory entry for one physical document copy |
| 2 | `renditions` | `renditions(document_id, agency_id) → documents`; the app role cannot perform this disposal |
| 3 | `documents` | `locations(document_id, agency_id) → documents` and the rendition reference must already be gone |
| 4 | `attestations` | `attestations(ledger_id, agency_id) → ledgers` |
| 5 | `ledgers` | both attestations and renditions restrict ledger deletion |
| 6 | `workdays` (cascades to `punches`) | `punches(workday_id, employee_id) → workdays` |
| 7 | `timelogs` | `punches(timelog_id, employee_id) → timelogs` |

The paired `UNIQUE (id, agency_id)` / `(id, employee_id)` pattern adds no new ordering, but it forces every composite reference to be removed consistently — deliberately preventing a convenient cross-tenant or cross-employee detachment. M8 must define a privileged, hold-aware disposal path because the ordinary application role deliberately cannot delete audit records.

**Disposing of an expired DTR package is a different and far safer operation than deleting an employee.** Employee deletion is restricted by `users`, deployments, enrollments, rosters, exemptions, overtimes, ledgers and possibly `workgroups.head_id`; `timelogs` additionally restrict deletion of the enrollments, syncs, terminals and manual-entry users they reference. Employment history is not the DTR and has its own retention basis. The phrase "purge the employee's trail" should not appear in a design.

### 5. The device undoes the purge

The sharpest finding, and it is specific to this architecture. Disposal deletes rows from Postgres. **The terminal still holds the enrollment and its own attlog.** `Terminal.stamp` is the read offset and "[r]esetting `stamp` to null forces a full resync" (`03-terminals.md` rule 5) — and import is `INSERT ... ON CONFLICT DO NOTHING` on the natural key, which no longer conflicts with anything once the rows are gone. **A full resync silently reinserts every lawfully destroyed timelog.**

Disposal is therefore not durable unless it reaches the device, or unless a disposal record makes reinsertion impossible. This also means blocking under §16(e) must reach the hardware: while the enrolment lives on the terminal, the device keeps collecting for that UID no matter what the database says.

### 6. Deleting from the primary database is not destruction from the system

IRR §19's disposal must "prevent further processing" ☆. The same personal data survives in: nightly dumps, PITR and WAL, replicas, generated exports and printed DTR PDFs, mail attachments, failed queue payloads, observability logs, and the raw sync payloads if retained. **A retention design that names only tables is incomplete**; it needs a data inventory per store.

### 7. Unresolved timelogs are governed by no clock at all

`03-terminals.md` rule 3: the resolve trigger leaves `employee_id` null when no enrolment covers the punch, and "[n]ull means unresolved and stays visible". `uid = 42` at a terminal at a time is personal data about *someone*, but no employee's retention clock can reach it. Same for raw sync payloads.

### 8. `restricted_at` is simultaneously too broad and unenforceable

Too broad, because it blocks the authorised processing the retention obligation itself requires. Unenforceable, because an Eloquent global scope is a UI convention: the person still appears in ledger exports, workday aggregates, supervisor dashboards, COA reports, audit history, raw timelog search, already-printed files and the terminal. On rehire, clearing the flag re-exposes the old restricted trail wholesale.

Blocking belongs in database-backed access policy or RLS (principle 7 already anticipates RLS "available later without a schema change"), scoped per data class and period, with regulator and legal access preserved — plus an actual revocation of the device enrolment. And it is **per record class, not per person**: a blanket "inside the window, request refused" is as wrong as a blanket deletion, because §16(e)'s other grounds bite independently of necessity.

### 9. An anonymised aggregate is usually not anonymous

A workgroup with one employee in June. Two employees where one's known leave makes the other's hours arithmetic. A monthly total keyed to workgroup, month or a surviving ledger id. Differencing two filtered reports to recover a suppressed cell. **A ledger keyed to an employee is never anonymised, and a hashed employee number is pseudonymisation while anyone holds the key.** Retaining statistics past the floor needs a documented re-identification assessment, cell suppression and coarsening — and for a small agency or unit, nothing at all.

### 10. The tombstone is itself retained personal data

A permanent `purges` row carrying agency, employee number, period, actor and counts has not retained "no record" — it has retained a durable employment record, indefinitely, and in an agency an employee number identifies the person directly. Disposal evidence needs its own purpose, access control and retention term, and should avoid identity where the audit does not require it.

### 11. The second Postgres role is theatre unless the credential is out of reach

A role boundary is real only if the deletion credential is unreachable from a compromised app. A Laravel scheduled command sharing the web app's deployment, environment, secret store, queue workers and network boundary just hands a compromised app a second password. Also: `REVOKE DELETE` says nothing about `TRUNCATE`; `SECURITY DEFINER` functions are privileged code and `timelogs_resolve` already updates resolution columns as owner; and no cascade route from an app-deletable parent up to `timelogs` exists **today**, which is fragile against later migrations rather than safe.

### 12. Retention configuration must be effective-dated

Changing an agency from 5 to 3 must not retroactively advance the disposal date of records already collected. This is principle 2 — "effective dates, never overwrite" — applied to a settings key, and the same reason decision 33 freezes `night_from` into the workday snapshot.

### 13. The biometric credential and the attendance record need different clocks

The legal pass supplied this and it is the one genuinely new *design* idea either reviewer produced: `Enrollment` and `Template` are **credentials**, `Timelog` is a **record**, and their lawful purposes end at different times.

| Data | Purpose ends | Consequence |
| --- | --- | --- |
| `Template`, active `Enrollment` | the day the employee separates — there is nothing left to authenticate | **purge promptly**, on the device and in the database; retaining it has no §12 basis at all |
| `Timelog`, `Workday`, `Ledger` | when the retention floor and any hold expire | **keep** for years afterwards |

So a separated employee's fingerprint should be gone long before their DTR is. A single `retention_years` applied to "the employee's data" gets this backwards, holding a credential for five years for no lawful purpose while treating the record it produced as one thing with it.

The enrolment *history* is not the credential. `03-terminals.md` rule 3 has timelogs referencing `enrollment_id`, and the resolve trigger's paired FK depends on it, so the row mapping a uid to an employee for a past punch is part of the record. What must go is the **live template and the device's ability to keep collecting**.

## I. What this changes in the design

| # | Change | Where |
| --- | --- | --- |
| 1 | **`03-terminals.md` rule 1's "Nothing is ever pruned" needs a terminus.** Unqualified, it is a DPA breach at the tail. The honest replacement keeps immutability and adds a lifecycle: *nothing is altered; expired record packages are disposed* | `03-terminals.md` rule 1 |
| 2 | `REVOKE DELETE ON timelogs FROM chronoz` is right for the request path and insufficient overall — disposal needs a path the request path cannot reach, with `TRUNCATE` revoked and `SECURITY DEFINER` functions audited | `07-constraints.md` |
| 3 | **The record series is all attendance records, one schedule, one clock per employee** (settled, section G). Timelogs, punches, workdays, ledgers, attestations, frozen renditions, any retained PDFs and document/location records, and future signed revisions and validation material are disposed of together; the clock runs from the series' last entry, so disposal keys on separation rather than a rolling sweep. Transient downloads are discarded immediately. Credentials are a different class with a shorter clock — delta 21 | M8 |
| 4 | **A scalar `retention_years` cannot express the civil-service rule.** CSC/COA retention is event-based ("1 year after post-audit and settlement"), so the minimum honest shape is a schedule per record class: legal basis, trigger type, effective date, hold override, disposal state. This stays individual agency-configured settings — it does not reintroduce a gov/private mode flag (decision 32) | M8; supersedes decision 32's implied key shape |
| 5 | **A legal and audit hold is first-class**, and §11's "establishment, exercise or defense of legal claims" is its statutory basis. Scope, basis, reference, custodian, asserted and released timestamps | M8 |
| 6 | **The duty is an eligibility review, not a nightly `DELETE`.** A missed disposal is not automatically unlawful; a disposal that ignores a hold is worse than one that did not run | M8 |
| 7 | **Attestations and canonical or signed PDFs must remain bound to what they certify** — destroying a ledger's workdays while retaining its evidential rendition or signature falsifies the package, while destroying the rendition leaves no exact signed bytes to verify | `06-attendance.md`, `07-constraints.md`; future digital signatures after M7 |
| 8 | **Disposal must reach the device, or a resync undoes it.** `stamp` reset plus `ON CONFLICT DO NOTHING` reinserts destroyed timelogs. Device de-enrolment and template deletion must be tracked commands with acknowledgement and escalation | `03-terminals.md` rules 2 and 5; M8 |
| 9 | **Erasure is answered per record class**: correct (§16(d), `voided_at` + `reason`) where accuracy is the ground, block where use or unlawful collection is the ground, refuse with a written cited basis where no ground is proven, dispose once eligible. Never a blanket answer either way | M8 |
| 10 | **Blocking belongs in RLS or a database-backed policy, not an Eloquent global scope**, and must survive rehire | M8; `02-access.md`, principle 7 |
| 11 | **§16(b) requires the retention period be disclosed before collection** — a copy and UI requirement on the enrolment screens | M8, enrolment screens |
| 12 | **Unresolved timelogs and raw sync payloads need their own clock** — no employee's clock reaches them | M8 |
| 13 | **The estate is bigger than the tables**: backups, PITR/WAL, replicas, exports, printed DTRs, queue payloads, logs. Not merely prudent — NPC Advisory 2021-01 puts erasure and blocking over data in both live *and back-up* systems, so a per-store inventory is part of the obligation | M8 |
| 14 | **Disposal evidence is itself personal data** — a tombstone carrying an employee number needs its own purpose, access control and term | M8 |
| 15 | **Retention settings must be effective-dated**, so a policy change never retroactively advances an existing record's disposal date (principle 2) | M8; `00-principles.md` tier 3 |
| 16 | **Statistics surviving disposal need a re-identification assessment**, cell suppression and coarsening; an employee-keyed ledger is never anonymous | M8 |
| 17 | Biometric templates are **not** §3(l) sensitive personal information, so `Template` needs no §13 basis — but it is NPC-high-risk and needs a documented proportionality record | phase 2; `03-terminals.md` |
| 18 | **A non-biometric alternative is a proportionality requirement**, and Rule X §7 supplies three methods. `source = 'manual'` with a required `user_id` is that path — recognise it as the compliance route, not an incidental feature | `03-terminals.md` rule 4 |
| 19 | **khronoz is a processor, each agency a controller.** Blocking and disposal execute *on an agency's instruction*, scoped to that agency; a cross-tenant platform purge would put khronoz in someone else's controller seat | M8; `02-access.md` |
| 20 | `voided_at` + `reason` already implements the §16(d) correction right, better than deletion would — a deleted punch cannot show that the correction happened. Worth stating as a privacy property, not only an audit one | `03-terminals.md` rule 1 |
| 21 | **Credentials and records need separate clocks.** A separated employee's live `Template` and device enrolment have no remaining §12 basis and should go promptly; their `Timelog` trail stays for years. One `retention_years` over "the employee's data" inverts this | phase 2; `03-terminals.md`; M8 |
| 22 | **What end-of-life removes is identifiability, not necessarily the row** — §11(f) permits historical and statistical processing, and longer storage "in cases laid down in law" with safeguards. That is the lawful route for a surviving statistic, and it is narrower than it sounds: see delta 16 | M8 |
| 23 | **A rights-request procedure is itself an obligation**, not just an answer. NPC Advisory 2021-01 requires a clear, simple procedure with request forms and identity verification. The agency runs it; khronoz supplies the mechanism (delta 19) | M8; interface |
| 24 | **Holding biometric data raises the breach obligation.** NPC Circular 16-03 names biometric data among the identity-fraud-enabling categories that make 72-hour notification mandatory, and a fingerprint cannot be reissued. An argument for holding the minimum, independent of §3(l) | phase 2; M8 |

## J. Open items

| # | Question | Status |
| --- | --- | --- |
| 1 | IRR of RA 10173 §19 in primary text | ☆ — the review supplied a full structure: §19(d) "[p]ersonal data shall not be retained longer than necessary", §19(d)(1)(a)–(c) the three permitted purposes, §19(d)(2) "[r]etention of personal data shall be allowed in cases provided by law", §19(d)(3) secure disposal "that would prevent further processing, unauthorized access, or disclosure". Two independent sources agree on the substance; `privacy.gov.ph`, the Official Gazette and the SC e-library all refused fetches. **Read in primary text before building M8** |
| 1a | IRR §18(c) (proportionality) and Rule X §§43–44 (outsourcing agreement contents) in primary text | ☆ — same access failure; substance attested |
| 1b | NPC Advisory No. 2021-01 in primary text | ☆ — **existence, date (29 January 2021) and title (*Data Subjects Rights*) independently confirmed**, and the denial grounds and the "live and back-up systems" scope attested by two independent secondary sources. The PDF itself 403'd. It is the most load-bearing instrument in section C, so read it |
| 1c | NPC Circular No. 16-03 §§11 and 18 in primary text | ☆ — the 72-hour rule and biometric data's place in the identity-fraud list are attested, not read |
| 2 | Has the NPC ruled squarely that a DTR is *not* §4(a)-exempt? | ★ — Advisory 2022-01's general holding was found; nothing on attendance records specifically. Section D rests on the statutory limiter, which is strong, and the review agreed independently |
| 3 | NPC Advisory No. 2022-01 and the biometrics advisory opinions in full | ★ — all PDF fetches 403'd |
| 4 | NAP GRDS Item 44's actual wording | ★ — inherited unverified from `csc-rules.md`. The *substance* ("1 year after posting to leave cards and post-audit") is attested by several agencies' own disposition schedules, but **the "Item 44" designation itself is unconfirmed** and may differ by agency. It is the government floor, so it deserves primary text before delta 4 is designed |
| 4a | Does PD 1445 §26's ten-year voucher preservation reach the **agency-held** DTR, or only the copy in COA's custody under §43(4)? | open, and material — it is the difference between a 1-year and a 10-year government floor. Both reviewers flagged the divergence; neither resolved it. §26 and §43(4) are verified primary text; the *application* is not |
| 4b | Is there any COA circular imposing an independent DTR retention figure? | ★ — COA Circular 2023-004 was cited by the review as listing DTRs among required supporting documents for disbursements; not read |
| 5 | The 2026 NPC circular replacing the blanket PIA rule with risk-based triggers naming biometric data | ★ — secondary press report only; if real it is a live obligation on phase 2 |
| 6 | ~~What legally *is* the record series?~~ | **decided 2026-09-11: all attendance records, as one series.** Sections G and H item 1 carry the reasoning and the accepted consequences. What stays open is narrower: whether an agency should be able to dispose of a *serving* employee's oldest records, which is the proportionality limb of H item 1 |
| 7 | Does an inspectable printable satisfy Rule X §11's "in or about the premises" for a cloud-hosted DTR? | ★ untested |
| 8 | Is khronoz a processor in every deployment, or a controller where it self-hosts a tenant? | product question, not a legal one — section F assumes hosted multi-tenant |
| 9 | Retention of the *attestation* once its ledger is disposed of — is a canonical rendition plus hash the right artifact, and what is its own term? | open, delta 7 |

## Sources

- RA 10173, Data Privacy Act of 2012 (primary, verified): https://lawphil.net/statutes/repacts/ra2012/ra_10173_2012.html
- Omnibus Rules Implementing the Labor Code, Book III, Rule X (primary, verified): https://library.laborlaw.ph/omnibus-rules-labor-code-book-3/ — cross-referenced against https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/2/85819 and https://natlex.ilo.org/dyn/natlex2/natlex2/files/download/77583/PHL77583.pdf
- IRR of RA 10173 ☆: https://privacy.gov.ph/wp-content/uploads/2023/06/IRR_RA-10173-as-amended.pdf (403), https://www.officialgazette.gov.ph/2016/08/24/implementing-rules-and-regulations-of-republic-act-no-10173/ (403), https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/2/70735 (TLS failure)
- NPC Advisory No. 2022-01, Request for Personal Data of Public Officers ★: https://privacy.gov.ph/wp-content/uploads/2022/08/NPC-Advisory-No.-2022-01-Request-for-Personal-Data-of-Public-Officers.pdf (403)
- NPC advisory opinions index ★: https://privacy.gov.ph/pips-and-pics/advisory-opinions/
- NPC Advisory No. 2020-03 ★: https://privacy.gov.ph/wp-content/uploads/2020/11/NPC-Advisory-No.-2020-03-FINAL.pdf (403)
- PD 1445, Government Auditing Code of the Philippines (primary, verified — full text extracted, 131 sections): https://www.gppb.gov.ph/wp-content/uploads/2023/06/Presidential-Decree-No.-1445.pdf — §26 (ten-year preservation of vouchers), §43(4) (auditors' custody of vouchers and supporting papers). **Note: the "§43 Disposition of Old Records" clause asserted by a reviewer does not exist in this decree**
- NPC Advisory No. 2021-01, *Data Subjects Rights*, 29 January 2021 ☆: https://privacy.gov.ph/wp-content/uploads/2021/02/NPC-Advisory-2021-01-FINAL.pdf (403) — existence, date and substance confirmed via independent commentary
- NPC Circular No. 16-03, *Personal Data Breach Management* ☆: not reached; §§11 and 18 attested
- Labor Code Art. 306 (formerly Art. 291), three-year prescription of money claims ☆
- NAP General Records Disposition Schedule Item 44 ★: inherited from `csc-rules.md`, not independently reached
