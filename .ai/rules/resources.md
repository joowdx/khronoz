---
paths:
  - 'app/Http/Resources/**'
---

# Resources

## One JsonResource per model, docblock names its TS interface
One JsonResource per model (UserResource, AgencyResource), always resolved with ->resolve() before handing to Inertia::render() — controllers.md already states that half. Its class docblock names the exact resources/js/types/index.d.ts interface it matches (e.g. "Matches the `Agency` interface..."), so a shape drift between the PHP side and the TypeScript side is easy to spot in review even though nothing enforces it automatically.

## A withCount aggregate lives in the page's row type, not on the shared interface
A count added by `withCount` is exposed with `whenCounted()`, so the key is absent everywhere the query did not ask for it and a missing count can never read as zero. Its TypeScript home is the consuming page's own row interface (`AgencyRow`, `WorkgroupRow`), never the shared `Agency`/`Workgroup` interface in `types/index.d.ts`.

Alias the count for what it answers, not for the relation it walks: `withCount(['deployments as people_count' => fn ($q) => $q->whereNull('ends')])` is a headcount, and `workgroups/index` also asks for the unaliased `deployments_count` because "who is here now" and "has anyone ever been here" are two different questions and one number cannot serve both.

Date-cast columns are sent as `->toDateString()`, never as the Carbon instance: app.timezone is Asia/Manila, and a bare date attribute JSON-serializes as a UTC instant, which for a positive offset always names the day before. The TS side types them `string` and never reparses them (`lib/dates.ts`).
