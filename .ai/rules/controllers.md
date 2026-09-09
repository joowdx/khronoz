---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Controller flow: Form Request, redirect with flash, JsonResource
Controllers: Form Request → action or model → redirect()->route(...)->with('success'|'error'); Inertia props through JsonResource::resolve(); cross-tenant requests must 404 (route binding under the scope).
