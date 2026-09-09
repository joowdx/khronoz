---
paths:
  - 'resources/js/pages/**'
---

# Pages

## AppLayout breadcrumbs take a plain string href, not a Wayfinder object
`BreadcrumbItem.href` (resources/js/types/index.d.ts) is typed as a plain `string`, unlike `Link`'s `href` prop which accepts a Wayfinder `RouteDefinition` object directly. Passing a bare route call (e.g. `href: index()`) into an `AppLayout` breadcrumbs array fails `tsc --noEmit` with "Type 'RouteDefinition<...>' is not assignable to type 'string | undefined'". Use `href: index().url` instead.

## Index filters: query string, partial reload, whitelisted server side
Every filter on a list lives in the query string, so the list is a link. The page holds one `Filters` interface, one `query(filters)` that drops defaults (so an unfiltered list is `/employees` and nothing else), and one `go(next)` that calls `router.get(index.url(), query(...), { only: PARTIAL, preserveState: true, preserveScroll: true })`. A search box debounces 250ms and adds `replace: true` so Back does not walk keystrokes.

`PARTIAL` lists only the props the list owns (`rows`, `pagination`, `filters`). A filter control's own options (`units`, `tags`) are deliberately excluded and are sent as Inertia closures in the controller — Inertia never invokes a closure for a prop a partial reload excluded, and the client keeps the previous value, so the option lists are queried once per full load rather than once per keystroke.

`EmployeeControllerTest::test_a_filter_reload_returns_only_the_props_the_list_owns` locks that, and it reads `PARTIAL` out of the page file rather than hard-coding the header — the same read-the-source approach `PermissionMatrixContractTest` takes — so adding `units` to the list makes the closure fire and the test fail. Falsifiability verified by doing exactly that. `AssertableInertia` cannot be used here: `fromTestResponse` reads the root view's `page` data, and a partial reload has no root view.

`go()` must build its query from the **local** search state, never from `filters.search`: `filters` is what the server last confirmed, so flipping a filter while the 250ms debounce is still in flight silently threw away what had just been typed. MEASURED: typing `abadilla` and toggling Exempt only inside the window now requests `/employees?search=abadilla&exempt=1`. The `next` argument still comes last, so Clear filters' own `search: ''` wins.

Server side, whitelist any filter whose value names a row or a vocabulary (`unit`, `tag`) against what the tenant actually has and report the dropped value as unset in `filters`, the way AgencyController::index whitelists `sort`. A mangled query string must not leave the list filtered by a value the picker cannot display.

Choosing a unit means that unit **and everything under it** (01-organization.md rule 4, `Unit::descendants()`): a department whose people all sit in its divisions would otherwise answer with nothing. Say so in the control's accessible name ("4 in Administrative Division and below").

## The units tree: indented table rows with hairline guides, not a widget
A tree is composed from §5.13's table, not from a new component: `lib/units.ts`'s `flattenUnits()` turns the flat `parent_id` list into tree order and hands each row `depth`, `last`, `children` and `guides`.

`guides` is one flag per indent slot above the row's own, and it is load-bearing: two siblings at depth 1 are almost never adjacent rows (everything under the first comes between), so the vertical line joining them has to be drawn by the rows in between. Without it the tree reads as disconnected ticks. Render a 22px `<span>` per guide slot carrying `border-l` where the flag is set, then the row's own elbow (an L for a last child, a T otherwise), then the name.

Draw the hairlines in **`border-input`** (the design's `--edge`, exposed under the shadcn-mapped name §9.4 trap 2 already documents — not a new token). The guides are what make a three-level tree read as a tree rather than as indentation, so they are meaning-bearing and WCAG 1.4.11 applies: a non-text graphical object needs 3:1 against its ground — **and a hovered row is a ground the guides are seen against too**, since `TableRow` paints `--row-hover` under the cursor. A row is hovered constantly (it is how you reach the row menu and the head combobox), so "against the panel at rest" is not the whole requirement. MEASURED against **both** grounds, both modes (WCAG 2.x relative luminance):

| Token | Light on `--card` | Light on `--row-hover` | Dark on `--card` | Dark on `--row-hover` | 1.4.11 |
| --- | --- | --- | --- | --- | --- |
| `--rule` | 1.14 : 1 | 1.05 : 1 | 1.09 : 1 | **1.00 : 1** | fails |
| `--edge-soft` | 1.48 : 1 | 1.36 : 1 | 1.42 : 1 | 1.30 : 1 | fails |
| `--tick` (previous) | 3.28 : 1 | 3.01 : 1 | 3.12 : 1 | **2.87 : 1** | fails on a hovered row, dark |
| `--edge` / `border-input` (now) | 3.45 : 1 | 3.17 : 1 | 3.78 : 1 | 3.48 : 1 | passes everywhere |

Fix round 3 corrected the dark column above for `--rule` and `--edge-soft`: the previous figures (1.20/1.09 and 1.57/1.42) were computed against `--canvas`/`--side`, not against the `--card`/`--row-hover` grounds the header names, and were mislabelled onto this table when it was written — recomputed from the hex values in `docs/design/mockups/tokens.css` they are 1.09/1.00 and 1.42/1.30. Worth stating on its own: dark `--rule` (`#1F1F1F`) and dark `--row-hover` (`#1F1F1F`) are the identical hex value, so that ratio is exactly 1.00 — on a hovered row in dark mode the guides were not merely low-contrast, they were painted in the exact colour of their own background and could not have been seen at all. That is the sharpest justification this rule can carry for why `--rule` was never a candidate.

`--rule` is invisible at one pixel and the tree collapsed to bare indentation. `--edge-soft` reads as a "non-essential inner divider" and is a third of the way to legible — the earlier version of this rule justified it by that role while justifying the change by the guides being structure the reader must see, which cannot both be true. §11's own `--tick` ruling chose its values precisely because they clear 3:1 "for an axis tick" — true, but only measured at rest against `--card`; nobody had checked it against `--row-hover`, where dark mode measures 2.87:1, the *same figure* §11 check 5 rejected `--acc` at on the dark meter trough, "because the bar is a graphical object under 1.4.11." The hover ground is the binding one — it is the worse of the two — so a token has to clear 3:1 there, not just at rest. `--edge` does, worst case 3.17:1 (light, hovered), and is the next-lightest token after `--tick` that does; `--muted` clears both grounds by a wide margin (4.89–7.11:1) but reads far heavier than a hairline needs. Not `--border` either: that is the panel's own edge and competes with it.

An `inline` combobox in the Head column shows `—` at rest (components.md), so a headless row reads as a fact rather than as four copies of the same invitation.

Bound the first column's width. With only the last column sized, the Unit column took 538 of 1210 and left a 280px void before the next label. Let a later text column be the flexible one instead.

## A panel must not squeeze the table inside it — the shell is what scrolls sideways
Two rules meet on any panel holding a wide table, and one arrangement satisfies both: §5.13 wants wide content to scroll rather than squeeze, and the sticky-head trap (components.md) forbids putting an `overflow` ancestor between the `th` and the shell's scroller, because that ancestor becomes the sticky scrollport and the head silently stops pinning at `top: var(--bar-h)`. CSS cannot give one axis `auto` and the other `visible`.

So the scrolling is the shell's. Every index panel carries **`min-w-min`** and its `<Table>` carries a **`min-w-[Npx]`** floor; `table-container` stays `overflow-x-clip` with nothing left to clip, and `#main-content` — already `overflow-auto` — is what scrolls horizontally. Both are needed: `min-w-min` alone stops at the table's *min-content*, which is the maximally squeezed layout where the `max-w-0` truncating cells collapse to nothing.

**`N` is computed, not hand-typed.** employees/index.tsx and units/index.tsx each carry a `COLUMNS` constant (one entry per fixed-width column) and a `FLEX_MIN` constant (what the one flexible column needs for the longest real string); the `<Table>`'s `min-w` is `Object.values(COLUMNS).reduce(...) + FLEX_MIN`, and every fixed `TableHead` reads its own width from the same `COLUMNS` object — so the floor cannot fall out of step with the columns the way a hand-typed `min-w-[1040px]` silently did (fix round 2: it had drifted 4px short of even its own comment's arithmetic). `TableColumnFloorContractTest` reads both files from disk and fails if a `TableHead` reverts to a hard-coded `w-[…px]` or the floor to a hard-coded number.

**A declared width is a preference, not a guarantee — `table-layout` is `auto`.** MEASURED in the browser, employees/index at its 1040 floor (800px viewport): Unit renders **212.6px**, Position **211.3px**, Tags **213px**, against 270/260/200 declared — the browser redistributes space across every column, not just the one under pressure. Even at 1440, with all the room the table asked for, they measure 259.1/250.7/213, still short of declared. `actions` renders at **68px** on both pages, at both the floor and at 1440, regardless of whether 64 or 68 is declared — table-layout:auto never gives it less, so `COLUMNS.actions` is 68 on both pages rather than a bare `w-16`. units/index does **not** show this squeeze: Unit/Kind/People hold their declared 480/150/110 exactly at both widths — its overflow comes entirely from Head, the flexible column, not from a fixed one being redistributed away from.

One employees/index cell still clips at the 1040 floor: **"Office of the Executive Director"** (a unit name) does not fit the 212.6px Unit column — 4 clipped `<td>`s at 800 against 3 at 1440 (the two-line "Human Resource Management Section" and "…Officer III" cells clip at both widths already). This corrects an earlier version of this rule, which claimed "zero clipped cells at 800" for employees/index — false, measured. units/index genuinely has zero clipped cells at 800: its fixed columns are not squeezed at all, per the previous paragraph.

MEASURED before any of this, at an 800px viewport: a 646px employees table sat in a 486px card, `scrollWidth > clientWidth` with nothing scrollable, and `Actions for …` landed at x 871-903 — outside the viewport, taking Edit and Remove with it. After: the button hit-tests as itself once the shell is scrolled, and clipping is reduced to the one cell above rather than eliminated. Dropping columns responsively was rejected: the overflow is data-dependent, so hiding one narrows the odds of an unreachable action rather than removing it. The cost accepted is that the sticky title bar's own content scrolls out horizontally with everything else.

`flattenUnits` treats a `parent_id` naming a row outside the list as a root rather than dropping it, so a scoped list never silently loses a subtree.

## A profile page: sections, leader-dot facts, history as a table, and dates as strings
A show page is three stacked sections divided by a rule with 32 either side (dashboard.tsx's `Stack`), never a page of cards. Order them by what changes and what has an action: for an employee that is "Where they work" first (the unit at 18/24/600 with its ancestry beneath and the move action in the section head), then §6.3's split of Personal | Employment as leader-dot rows, then the history in the page's one panel.

Leader-dot rows follow `.kv`: `items-baseline`, min-height 38, the rule on `& + &` so the list closes without a trailing hairline, the value right at 600 `tabular-nums`, and a muted normal-weight value for anything the record does not hold, so an empty field never reads as a filled one.

History is a table, not a decorative timeline — the rows get compared against paper. The open row takes `data-state="selected"` (§5.13's tint) **and** says "Present" in words; colour is never the only cue.

One primary action per page, in the heading row, filled (`Edit record`). A section-scoped action stays `outline` even when it is the only thing to do — MEASURED: two outline buttons 46px apart read as a pair of equals and neither led.

A date field's `min`/`max` must enforce what the database will actually accept. A move closes the open placement the day before the new one starts, so `starts` on or before that placement's own `starts` leaves `ends < starts` and `deployments_dates_ordered` refuses the whole transaction — so the floor is the **later** of `hired_at` and the day after the current placement began (`lib/dates.ts`'s `laterDay`/`addDay`), and the hint says which of the two set it. MEASURED: with `min` at `hired_at` alone, an employee hired in 2019 whose placement began in 2026 was offered a seven-year window in which every date was fatal. The constraint still decides — a concurrent move can make a legal-looking date illegal between render and submit, and EmployeeDeploymentController translates both 23P01 and 23514 — this is only the control no longer inviting the refusal.

**Never `new Date(...)` a date the API sent.** `birthdate`, `hired_at`, `separated_at`, `starts` and `ends` are `YYYY-MM-DD` strings, so they bind straight to `<input type="date">`; `lib/dates.ts`'s `formatDay()` splits the string and builds no Date at all, and `manilaToday()` is what a date field defaults to. Comparing two of them is a plain string comparison, which is exactly chronological (`laterDay`). `addDay()` is the one exception and it never touches a zone: parts out of the string into `Date.UTC` (which normalises 32 January, month lengths and leap years), back out through `toISOString`. Rolling the calendar by hand instead would mean shipping a month-length table to avoid a `Date` that is already exact.

Do not offer an action that cannot mean anything: a separated employee with no open placement gets a plain statement, not an invitation to deploy them.
