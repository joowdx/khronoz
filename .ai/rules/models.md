---
paths:
  - 'app/Models/**'
---

# Models

## Tenant models use BelongsToAgency
Every model with agency_id except User and Agency uses App\Models\Concerns\BelongsToAgency (global AgencyScope keyed to App\Tenancy\Tenant, fail-closed outside real console runs, agency_id filled on creating). User has no scope: list users through the tenant's agency relation; {user} bindings are scoped by resolveRouteBindingQuery. To cross tenants use withoutGlobalScope(AgencyScope::class) and say why. Holidays get their own scope (own + platform). Agency hides the platform row; reach it with Agency::platform().

## A Scout-searchable tenant model must scope its index by agency_id
Scout results bypass Eloquent global scopes entirely (an external driver returns ids straight from its own index, not through AgencyScope). If a tenant model ever uses Laravel\Scout\Searchable, its toSearchableArray() must include agency_id, and every ::search() call site must filter by it (->where('agency_id', ...) or the driver equivalent). User does not use Searchable today for exactly this reason: it was added ahead of any real search feature with agency_id absent from toSearchableArray(), which would have been a silent cross-tenant leak the moment a caller used ::search() with a non-database SCOUT_DRIVER. Removed until a real search feature needs it.

## Scout's scope behaviour is driver-dependent
Scout's scope behaviour is driver-dependent. `DatabaseEngine::newSearchQuery()` falls back to `Model::newQuery()`, so the shipped `SCOUT_DRIVER=database` carries every global scope. External engines match their own index and only scope the rehydration, so hit counts, ordering and pagination escape `AgencyScope` even though the returned rows do not. Hence `agency_id` in `toSearchableArray()` **and** an explicit filter at every `::search()` call site — the protection is a property of the driver, not of the code.
