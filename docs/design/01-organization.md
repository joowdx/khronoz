# 01 — organization

```mermaid
erDiagram
    AGENCIES  ||--o{ WORKGROUPS       : "owns"
    AGENCIES  ||--o{ EMPLOYEES   : "owns"
    WORKGROUPS     |o--o{ WORKGROUPS       : "parent, null = top level"
    WORKGROUPS     ||--o{ DEPLOYMENTS : "places"
    EMPLOYEES ||--o{ DEPLOYMENTS : "moves through, one substantive open at a time"
    DEPLOYMENTS |o--o{ DEPLOYMENTS : "a movement departs from a substantive placement"
    EMPLOYEES |o--o{ WORKGROUPS       : "heads, one employee may head several"

    AGENCIES {
        ulid id PK
        string code UK
        string name
        boolean platform "exactly one true row: owns defaults and superusers"
    }
    WORKGROUPS {
        ulid id PK
        ulid agency_id FK
        ulid parent_id FK "nullable = top level, same agency"
        string kind "department, division, section, unit, office... label only"
        string code
        string name
        ulid head_id FK "nullable"
    }
    EMPLOYEES {
        ulid id PK
        ulid agency_id FK
        string number UK "employee no"
        string first_name
        string middle_name
        string last_name
        string suffix
        string position
        json tags "free-form agency labels, read by no rule"
        boolean exempt "no DTR expected"
        timestamp deleted_at
    }
    DEPLOYMENTS {
        ulid id PK
        ulid agency_id FK
        ulid employee_id FK
        ulid workgroup_id FK
        ulid parent_id FK "nullable, the substantive placement this movement departs from"
        date starts
        date ends "nullable = current"
    }
```

## Rules

1. A workgroup's parent is null or another workgroup of the same agency, enforced by a paired FK on `(parent_id, agency_id)`. No cycles, enforced by trigger.
2. An employee has at most one **substantive** deployment per date and at most one **movement** per date: two partial exclusion constraints on `employee_id` and `daterange(starts, ends, '[]')`, partitioned by `parent_id IS NULL`. Each also yields at most one open row of its class. A movement may overlap the substantive placement it departs from — that nesting is the point — but never another movement (nobody is detailed to two places at once). See rule 7.
3. Exactly one agency row has `platform` true, enforced by a partial unique index. It owns the shared rows: national holidays, default shifts and schedules, superusers. It has no employees, workgroups, terminals or teams, enforced by trigger, cannot be deleted, and the flag cannot move. Eloquent hides it from `Agency` queries with a global scope; it is reached through `Agency::platform()`, so lists, counts and reports never see it.
4. "This workgroup and everything under it" is a recursive CTE over `parent_id`.
5. Workgroups are the formal structure: one placement at a time, through `Deployment`. Tags are labels on the employee — free-form, agency-defined, read by no rule — and the way you select many employees at once for an ad-hoc bulk action. A `Team` (04-scheduling.md) is a named `(schedule, anchor)` cohort and is the only one of the three that decides what a person is expected to work. A fact you filter by is a tag; a rotation you belong to is a team. `meta` is inert payload nothing filters on.

6. Deployment ranges are the employment history (decision 28). A placement **covering today** means current employment — not merely an open one, because a placement may be fixed-term where the appointment nature is contractual, casual or co-terminous (decision 36). The first start and final end record the span, and a new range after a gap records rehire without overwriting earlier service. A person with no placement is not yet started for timekeeping. Tags may distinguish departure from a gap, but no computation reads them. Ending a placement includes its last day and opens no replacement. Removal handles every row that has not finished — each placement and reassignment whose range has not closed before today — closing what began and deleting what never started, innermost first, then soft-deletes the employee in the same transaction.

   "Covering a date" and "has no end" are not interchangeable, and only the first is ever at most one: a placement ending in June and its successor starting in July have both not ended, while no two substantive placements can share a single day, which is what `deployments_no_overlap` guarantees. Every "the current placement" read is therefore date-bounded on both sides.

## Shapes it covers

```
Agency A                          Agency B                  Agency C
  Treasury       department         Admin      division       Engineering  department
    Collection   division             employees here            employees here
      employees here
```

7. **A reassignment or detail is a deployment nested inside another** (decision 31). `parent_id` null means the row is the employee's *substantive* placement — where their plantilla item sits. `parent_id` set means the row is a *movement*: the person works in another workgroup for a period while the substantive placement stays open, because the item never left. `parent_id IS NOT NULL` is the whole fact; there is no separate type column, because a Philippine agency's vocabulary for the same arrangement varies — a provincial LGU says "detail" where the Civil Service Commission's term of art is "reassignment" — and no rule reads the label (decision 25's reasoning for `employees.tags`). A **transfer** is not a movement: the item moves, so the substantive row closes and a new substantive row opens, which is what a plain deploy already does.

   The parent is the same employee for free — `UNIQUE (id, employee_id)` plus a paired FK on `(parent_id, employee_id)`, the same trick as `(x_id, agency_id)`. A movement's range sits inside its parent's, and a movement's parent is itself substantive (no detail from a detail); both are triggers, because both are cross-row.

   **Two verbs write here, and a third does not exist** (decision 35). `TransferEmployee` is the item moving: it closes the open substantive row at `starts - 1` and opens a new one, which is also what an initial placement and a rehire after a gap are. `ReassignEmployee` is the person moving while the item stays: it closes nothing and inserts a row with `parent_id` set. Both arrive at `POST employees/{employee}/deployments`, where a boolean `reassignment` chooses between them — **the parent is never sent**, because it is always the employee's own open substantive placement and the server already holds it.

   **A mistake is deleted, not corrected.** There is no PATCH: a wrongly recorded deployment was never true, so `DELETE employees/{employee}/deployments/{deployment}` removes it and the right row is created afresh. `Deployment` therefore carries no `SoftDeletes` and must never acquire it — a soft delete is an `UPDATE`, so the row would keep its range, go on occupying the timeline the exclusion constraints index, and refuse its own replacement with `23P01`. A placement with a movement still nested under it refuses deletion (`23001`) until the movement goes first.

   **Three consumers read this nesting differently, and they must not be collapsed into one "operative placement" notion:**

   | Consumer | Resolves from |
   | --- | --- |
   | `head`, the final attestation (06-attendance.md rule 3) | the **substantive** row, walked to its root workgroup. The mother department approves and submits, so it signs last. |
   | a work suspension (05-calendar.md rule 3) | the **operative** row — the movement if one covers the date, else the substantive one. A closure declared on the mother workgroup cannot excuse a day the person actually worked elsewhere. |
   | visibility (02-access.md rule 5) | **any** overlapping row. Both workgroups see a month the person spent partly in each — and the mother must, because it attests those months. The substantive row staying open is what grants that, and a transfer closing it is what ends it. |
   | `supervisor` (06-attendance.md rule 3) | **open.** Whether the receiving or the mother workgroup's head certifies attendance depends on the arrangement; unsettled until a real case decides it. |
