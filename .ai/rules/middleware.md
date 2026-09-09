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
