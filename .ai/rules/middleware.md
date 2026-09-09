---
paths:
  - 'app/Http/Middleware/**'
  - 'bootstrap/app.php'
---

# Middleware

## SetTenant must run before SubstituteBindings
SetTenant must sit in the middleware priority list immediately before SubstituteBindings, inserted via $middleware->prependToPriorityList(SubstituteBindings::class, SetTenant::class) in bootstrap/app.php. Appended middleware otherwise runs after SubstituteBindings, so route model bindings would resolve before the tenant is known and every tenant-scoped {model} binding would 404; tests/Feature/Http/Controllers/UserControllerTest.php's test_editing_a_colleague_renders (arriving in Task 9) is the regression guard. SetTenant must also appear exactly once in the single $middleware->web(append: [...]) call, ahead of HandleInertiaRequests — appending Inertia's middleware twice would run it twice per request.
