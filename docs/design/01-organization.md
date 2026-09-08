# 01 — organization

```mermaid
erDiagram
    AGENCIES  ||--o{ UNITS       : "owns"
    AGENCIES  ||--o{ EMPLOYEES   : "owns"
    UNITS     |o--o{ UNITS       : "parent, null = top level"
    UNITS     ||--o{ DEPLOYMENTS : "places"
    EMPLOYEES ||--o{ DEPLOYMENTS : "moves through, one open at a time"
    EMPLOYEES |o--o| UNITS       : "heads"
    AGENCIES  ||--o{ GROUPS      : "owns"
    GROUPS    ||--o{ MEMBERS     : "has"
    EMPLOYEES ||--o{ MEMBERS     : "joins, many at once"

    AGENCIES {
        ulid id PK
        string code UK
        string name
        boolean platform "exactly one true row: owns defaults and superusers"
    }
    UNITS {
        ulid id PK
        ulid agency_id FK
        ulid parent_id FK "nullable = top level, same agency"
        string kind "department, division, section, office... label only"
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
        enum sex
        date birthdate
        string email
        string mobile
        string position
        enum status "permanent, casual, contractual, ..."
        boolean exempt "no DTR expected"
        date hired_at
        date separated_at "nullable"
        timestamp deleted_at
    }
    DEPLOYMENTS {
        ulid id PK
        ulid employee_id FK
        ulid unit_id FK
        date starts
        date ends "nullable = current"
    }
    GROUPS {
        ulid id PK
        ulid agency_id FK
        string name
        string description
    }
    MEMBERS {
        ulid id PK
        ulid group_id FK
        ulid employee_id FK
        date starts
        date ends "nullable"
    }
```

## Rules

1. A unit's parent is null or another unit of the same agency, enforced by a paired FK on `(parent_id, agency_id)`. No cycles, enforced by trigger.
2. An employee has at most one deployment per date: exclusion constraint on `employee_id` and `daterange(starts, ends)`, which also yields at most one open deployment.
3. Exactly one agency row has `platform` true, enforced by a partial unique index. It owns the shared rows: national holidays, default shifts and schedules, superusers. It has no employees, units, terminals or groups, enforced by trigger, cannot be deleted, and the flag cannot move. Eloquent hides it from `Agency` queries with a global scope; it is reached through `Agency::platform()`, so lists, counts and reports never see it.
4. "This unit and everything under it" is a recursive CTE over `parent_id`.
5. Units are the formal structure, one placement at a time. Groups are generic, cross-cutting containers: an employee may be in many. They exist for filtering and bulk actions such as rostering every member at once. A group owns nothing.

## Shapes it covers

```
Agency A                          Agency B                  Agency C
  Treasury       department         Admin      division       Engineering  department
    Collection   division             employees here            employees here
      employees here
```
