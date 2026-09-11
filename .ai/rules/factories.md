---
paths:
  - 'database/factories/**'
  - 'database/seeders/**'
---

# Factories & Seeders

## Factories set every column explicitly
Model::shouldBeStrict() (AppServiceProvider) throws MissingAttributeException on any column no attribute was ever set for, and create() never re-selects the row afterwards — so a factory's definition() must set every real column explicitly, never omit one hoping the database default fills it in. Named states (platform(), invited(), forAgency()) layer on top for common variations.

## Seeders never truncate() and must set agency_id explicitly
Seeders never call truncate() — the app role isn't granted it (migrations.md). They must always set agency_id explicitly too: a seeded row gets no free pass around the tenant column just because a seeder, not a request, is creating it.

## A factory that invents a json shape is a test that proves nothing
WorkdayFactory hand-wrote workdays.shift as a flat {name, slots, required, flex, remote}. Snapshot::of() writes {shift: {...}, settings: {...}, holidays: [], suspensions: []} — decision 69. So WorkdayResource read $workday->shift['name'], the assertion passed against the factory's fiction, and the Shift column was empty on every real row. 1296 tests and 17 mutations missed it; a screenshot found it in a second.

For any json column the application writes through a named builder, the factory must produce what that builder produces, and a contract test must compare the two key structures. A hand-written fixture of a shape the code owns drifts the moment the code changes, and the tests go on passing.
