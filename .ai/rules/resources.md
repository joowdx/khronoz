---
paths:
  - 'app/Http/Resources/**'
---

# Resources

## One JsonResource per model, docblock names its TS interface
One JsonResource per model (UserResource, AgencyResource), always resolved with ->resolve() before handing to Inertia::render() — controllers.md already states that half. Its class docblock names the exact resources/js/types/index.d.ts interface it matches (e.g. "Matches the `Agency` interface..."), so a shape drift between the PHP side and the TypeScript side is easy to spot in review even though nothing enforces it automatically.
