---
paths:
  - 'app/Http/Middleware/**'
  - 'bootstrap/app.php'
---

# Middleware

## SetTenant must run before SubstituteBindings
SetTenant must sit in the middleware priority list immediately before SubstituteBindings, inserted via $middleware->prependToPriorityList(SubstituteBindings::class, SetTenant::class) in bootstrap/app.php. Appended middleware otherwise runs after SubstituteBindings, so route model bindings would resolve before the tenant is known and every tenant-scoped {model} binding would 404; tests/Feature/Http/Controllers/UserControllerTest.php's test_editing_a_user_of_another_agency_is_not_found (arriving in Task 9) is the regression guard. SetTenant must also appear exactly once in the single $middleware->web(append: [...]) call, ahead of HandleInertiaRequests — appending Inertia's middleware twice would run it twice per request.

## The cross-tenant 404 test is the real middleware-order tripwire, not the happy-path one
A cross-tenant lookup is the only shape that can detect this ordering regression: User::resolveRouteBindingQuery's agency_id filter only narrows an already-unique id lookup, so skipping it can never change which row a legitimate same-tenant colleague resolves to, only whether a cross-tenant one incorrectly resolves — confirmed by temporarily deleting bootstrap/app.php's prependToPriorityList(SubstituteBindings::class, SetTenant::class) and running the suite: UserControllerTest's test_editing_a_colleague_renders (same-tenant colleague) still passed 200 under the mutation. test_editing_a_user_of_another_agency_is_not_found is what actually flips (404 to 200) under this exact regression. If this constraint needs a regression guard, assert against a cross-tenant lookup, not a same-tenant happy path — the latter cannot detect it by construction.

## EnsureAgency (`agency`) is the boundary the Organization routes need, not the nav gate
`workgroups` and `employees` both carry an `agency_not_platform` trigger, so on the platform tenant every write on those routes is refused by the database with P0001. Nothing above the database saw that: `SetTenant` defaults a platform user's tenant to the platform agency itself and `Gate::before` grants a superuser every ability. MEASURED before the fix — `GET /employees/create` answered **200** and the form rendered and filled in; `POST /employees` answered **500**. The sidebar hid the nav group (app-sidebar.tsx, OrganizationNavContractTest), which is why nobody found it: hiding a link is a courtesy, not a boundary.

`EnsureAgency` 404s when `Tenant::id()` equals `Tenant::platformId()`, and `routes/web.php` wraps both resources in it. 404 rather than 403, because the rows these screens are about cannot exist for this tenant at all — the same answer a cross-tenant lookup already gives everywhere else.

`EnsureAgencyTest` asserts only the routes with **no bound model**: `workgroups.edit`, `employees.show` and the rest bind a `{model}` that AgencyScope already refuses across tenants, so they answer 404 whether this middleware runs or not and cannot detect it being dropped — the same rule the cross-tenant tripwire above follows. It also asserts the positive: one `enter` away, the same superuser gets both screens, so a middleware that 404s everything would not pass.

## Toast messages use native Inertia flash
HandleInertiaRequests bridges Laravel session success/error messages into Inertia 3 native page.flash and consumes the legacy keys. Never share these as ordinary props: history and partial responses replay them. The single application-bootstrap flash listener and root Toaster handle all pages; do not add layout toast effects.
