---
paths:
  - 'app/Models/**'
---

# Models

## Tenant models use BelongsToAgency
Every model with agency_id except User and Agency uses App\Models\Concerns\BelongsToAgency (global AgencyScope keyed to App\Tenancy\Tenant, fail-closed outside real console runs, agency_id filled on creating). User has no scope: list users through the tenant's agency relation; {user} bindings are scoped by resolveRouteBindingQuery. To cross tenants use withoutGlobalScope(AgencyScope::class) and say why. Holidays get their own scope (own + platform). Agency hides the platform row; reach it with Agency::platform(). SetTenant must stay in the middleware priority list before SubstituteBindings.
