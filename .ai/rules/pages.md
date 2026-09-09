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

Server side, whitelist any filter whose value names a row or a vocabulary (`unit`, `tag`) against what the tenant actually has and report the dropped value as unset in `filters`, the way AgencyController::index whitelists `sort`. A mangled query string must not leave the list filtered by a value the picker cannot display.

Choosing a unit means that unit **and everything under it** (01-organization.md rule 4, `Unit::descendants()`): a department whose people all sit in its divisions would otherwise answer with nothing. Say so in the control's accessible name ("4 in Administrative Division and below").

## The units tree: indented table rows with hairline guides, not a widget
A tree is composed from §5.13's table, not from a new component: `lib/units.ts`'s `flattenUnits()` turns the flat `parent_id` list into tree order and hands each row `depth`, `last`, `children` and `guides`.

`guides` is one flag per indent slot above the row's own, and it is load-bearing: two siblings at depth 1 are almost never adjacent rows (everything under the first comes between), so the vertical line joining them has to be drawn by the rows in between. Without it the tree reads as disconnected ticks. Render a 22px `<span>` per guide slot carrying `border-l` where the flag is set, then the row's own elbow (an L for a last child, a T otherwise), then the name.

Draw the hairlines in `--edge-soft`, the token for a "non-essential inner divider". MEASURED: `--rule` (#F0F0F0, 1.14:1) is invisible at one pixel and the tree collapsed to bare indentation; `--border` is the panel's own edge and competes with it.

Bound the first column's width. With only the last column sized, the Unit column took 538 of 1210 and left a 280px void before the next label. Let a later text column be the flexible one instead.

`flattenUnits` treats a `parent_id` naming a row outside the list as a root rather than dropping it, so a scoped list never silently loses a subtree.

## A profile page: sections, leader-dot facts, history as a table, and dates as strings
A show page is three stacked sections divided by a rule with 32 either side (dashboard.tsx's `Stack`), never a page of cards. Order them by what changes and what has an action: for an employee that is "Where they work" first (the unit at 18/24/600 with its ancestry beneath and the move action in the section head), then §6.3's split of Personal | Employment as leader-dot rows, then the history in the page's one panel.

Leader-dot rows follow `.kv`: `items-baseline`, min-height 38, the rule on `& + &` so the list closes without a trailing hairline, the value right at 600 `tabular-nums`, and a muted normal-weight value for anything the record does not hold, so an empty field never reads as a filled one.

History is a table, not a decorative timeline — the rows get compared against paper. The open row takes `data-state="selected"` (§5.13's tint) **and** says "Present" in words; colour is never the only cue.

One primary action per page, in the heading row, filled (`Edit record`). A section-scoped action stays `outline` even when it is the only thing to do — MEASURED: two outline buttons 46px apart read as a pair of equals and neither led.

**Never `new Date(...)` a date the API sent.** `birthdate`, `hired_at`, `separated_at`, `starts` and `ends` are `YYYY-MM-DD` strings, so they bind straight to `<input type="date">`; `lib/dates.ts`'s `formatDay()` splits the string and builds no Date at all, and `manilaToday()` is what a date field defaults to.

Do not offer an action that cannot mean anything: a separated employee with no open placement gets a plain statement, not an invitation to deploy them.
