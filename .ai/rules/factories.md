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
