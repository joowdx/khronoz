---
paths:
  - 'app/Models/**'
---

# Models

## Tenant models use BelongsToAgency
Every model with agency_id except User and Agency uses App\Models\Concerns\BelongsToAgency (global AgencyScope keyed to App\Tenancy\Tenant, fail-closed outside real console runs, agency_id filled on creating). User has no scope: list users through the tenant's agency relation; {user} bindings are scoped by resolveRouteBindingQuery. To cross tenants use withoutGlobalScope(AgencyScope::class) and say why. Holidays get their own scope (own + platform). Agency hides the platform row; reach it with Agency::platform().

## A Scout-searchable tenant model must scope its index by agency_id
Scout results bypass Eloquent global scopes entirely (an external driver returns ids straight from its own index, not through AgencyScope). If a tenant model ever uses Laravel\Scout\Searchable, its toSearchableArray() must include agency_id **for every driver that filters through its own index** — i.e. everything except the shipped `database` one, which filters in SQL and carries AgencyScope anyway (`Employee::toSearchableArray()` includes the key only when `config('scout.driver') !== 'database'`, and says why). Every ::search() call site must filter by it regardless of driver (->where('agency_id', ...) or the driver equivalent).

Nothing else identifier-shaped belongs in that array under the `database` driver: it ilike-matches `%term%` against every key, so an indexed `id` made every short term match every row (MEASURED: `q` returned 32 of 32 employees). Scout never needs the key in the array — each engine merges `getScoutKey()` into the document itself. User does not use Searchable today for exactly this reason: it was added ahead of any real search feature with agency_id absent from toSearchableArray(), which would have been a silent cross-tenant leak the moment a caller used ::search() with a non-database SCOUT_DRIVER. Removed until a real search feature needs it.

## Scout's scope behaviour is driver-dependent
Scout's scope behaviour is driver-dependent. `DatabaseEngine::newSearchQuery()` falls back to `Model::newQuery()`, so the shipped `SCOUT_DRIVER=database` carries every global scope. External engines match their own index and only scope the rehydration, so hit counts, ordering and pagination escape `AgencyScope` even though the returned rows do not. Hence `agency_id` in `toSearchableArray()` **and** an explicit filter at every `::search()` call site — the protection is a property of the driver, not of the code.

## Workgroup is the container; "unit" is one of its kinds
Do not rename `Workgroup` back to `Unit` (decision 29). A Philippine agency's hierarchy runs department → division → section → **unit**, so "unit" is a legitimate value of `workgroups.kind`, and the container cannot share the name.

The collision cannot be fixed on the `kind` side. `kind` is a plain nullable string with no CHECK and no PHP enum on purpose, because 06-attendance.md rule 3 resolves the `head` attestation role as "the head of the nearest ancestor workgroup **of the kind the setting names**". The vocabulary belongs to the agency, so `kind = 'unit'` is always reachable and nothing may forbid it.

`Workgroup` and `Team` are near-synonyms in English and are deliberately different things: a workgroup is where a person is *placed* (one at a time, via `Deployment`, under Organization); a team is the rotation they are *rostered into* (04-scheduling.md, under Scheduling).

Also do not rename: PHPUnit's `tests/Unit` suite, `app()->runningUnitTests()`, or 04-scheduling.md's "Civil Security Unit" worked example — that last one is a real office's own name and the clearest illustration of the collision.

## Employee identity is limited to timekeeping needs
Employee sex, birthdate, email and mobile were removed for data minimization. Keep users.email for login/invitations/recovery; do not reintroduce employee contact or demographic fields without an established purpose. Employment-record privacy rights remain applicable; account deletion does not automatically erase attendance.
