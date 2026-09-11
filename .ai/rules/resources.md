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

## whenHas for an aggregate, never whenNotNull
To expose a column or aggregate that only some queries select, use `$this->whenHas('key')`. Do not use `$this->whenNotNull($this->key)`.

`whenNotNull` takes a *value*, so `$this->key` is evaluated before the method runs. On a model loaded by a query that did not select that key, `Model::shouldBeStrict()` (AppServiceProvider) throws `MissingAttributeException` — and inside a controller that surfaces as a 500 and, in a test, as the unhelpful "Not a valid Inertia response".

`whenHas` asks the model whether the attribute exists at all, which is the actual question, and still keeps the key absent so a missing value can never read as zero or "never".

Found on `TerminalResource::last_import_at`, added by a `withMax` that only the index issues: `edit` and the enrollments page load the same terminal without it and both 500'd. Counts are already safe — `whenCounted` checks for the key rather than reading it.

## whenLoaded does not guard a nullable relation
`OtherResource::make($this->whenLoaded('rel'))->resolve()` crashes with "Attempt to read property id on null" whenever `rel` is a **nullable** belongsTo that loaded as null. `whenLoaded` only asks whether the relation was loaded, not whether it found anything — an eager-loaded null is loaded.

Write it as:

```php
'rel' => $this->whenLoaded('rel', fn () => $this->rel === null ? null : OtherResource::make($this->rel)->resolve()),
```

In a controller this surfaces as a 500 and, in a test, as the unhelpful "Not a valid Inertia response" — nothing names the column.

It has bitten three times in one milestone, each on the row the screen most exists to show: `TerminalResource::workgroup` (an agency-wide terminal, the commonest row on the index), `TimelogResource::terminal`, and `TimelogResource::employee` — where null means *unresolved*, which is exactly what the timelogs screen's main filter is built to find.

Rule of thumb: if the migration writes `->nullable()` on the foreign key, the resource needs the closure.

## Enum labels come from the enum, never a map in TypeScript
Never restate an enum's display words in a TS lookup map. The cases are held to
the database CHECK by EnumCheckContractTest; a third copy in the front end is
held to neither and drifts silently — offering a value the CHECK refuses, or
missing one it allows.

Ship `{value, label}` from PHP instead, the shape UserResource's
permission_groups and UserController::accesses() already use:
- Resource: `'type' => ['value' => $this->type->value, 'label' => $this->type->label()]`
- Controller: a private `types()` mapping `Enum::cases()` for the picker's options.
The page renders what it is given and knows no vocabulary of its own.

Cost of getting this wrong, twice in one session: `resources/js/lib/calendar.ts`
(now deleted) dropped `ExemptionType::Emergency` entirely, mislabelled Personal,
and invented an `emergency` overtime mode the CHECK allows only `pay`/`cto`.
