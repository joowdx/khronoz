---
paths:
  - 'resources/js/components/**'
---

# Components

## The shell: one sticky title bar, navigation as data, appearance in the user menu
Design source of record for every shell part: docs/design/08-interface.md plus docs/design/mockups/ (tokens.css, ui.css, the numbered artboards). The mockups win when they and the doc disagree.

`components/page-header.tsx` draws both the sticky bar and the page's heading row, and every page in the shell renders it as its first child, passing `title`, an optional single-parent `breadcrumb`, an optional `description`, `live` for the positive dot, and `actions` for the page's one primary action. The **bar** (56 high, sticky top 0, z 30, `-mx-8 px-8` to escape the content column's padding, its 1px rule and its sidebar trigger both unconditional) carries the trigger and the breadcrumb trail and nothing else. The **title, description and primary action** sit in a `justify-between` row in the content, not in the bar: 56px cannot hold a 24/30 title, a description and a 36px button, and a title belongs to its page rather than to the chrome (owner, 2026-09-09, following the sibling project `paayo`). Do not hand-roll either part in a page, and do not move the title back into the bar. The month stepper is §5.2's third form and is unbuilt until a month-scoped page exists (Workdays, Milestone 6); it will replace the title in the heading row.

`app-layout.tsx` owns the scroll container: the content column scrolls, not the window. That is what makes both sticky layers work — the bar at `top: 0` and a table head at `top: var(--bar-h)` (56). The container carries `data-stuck` for anything that wants to know the scroller has moved; the bar does not use it. Its 1px bottom rule and its sidebar trigger are unconditional, at every width: a rule-less strip on the canvas reads as page content, not chrome, and every Milestone 1 page is shorter than the viewport so a scroll-conditional rule never appeared at all. A panel wrapping a table must NOT set `overflow: hidden` for its rounded corners: an `overflow` ancestor becomes the sticky scrollport and the head silently stops sticking.

Navigation is data, not markup. `app-sidebar.tsx` builds a `NavGroup[]` and `nav-main.tsx` renders it; a milestone adds a group to the array. Only what exists is rendered — a nav item or menu entry that leads nowhere is worse than an absent one. A count on a nav item means work is held up, not how many rows the page has. The group label is a `<div>` referenced by the list's `aria-labelledby`, never a heading: the sidebar precedes the page in the DOM, so a heading there lands above the page's own `<h1>`.

The user menu (`user-menu.tsx`) owns appearance. Light/Dark/System is the only appearance control in the product and it is wired to `hooks/use-appearance.ts`. Inside a Radix menu the segmented control must be `DropdownMenuSegmented` / `DropdownMenuSegmentedItem` (menuitemradio), not `toggle-group.tsx`: a menu swallows Tab and keeps focus in its own roving group, so plain buttons in the surface are unreachable by keyboard.

`hooks/use-manila-clock.ts` is the one clock. Asia/Manila through `Intl`, assembled from `formatToParts` (no locale prints what the artboards draw), ticking on the minute. A day runs 06:00 to 30:00, so the day strip's date is the open window's date, not the calendar date.

## The matrix is a fourth copy of the permission set, and a locked box is aria-disabled
`AREAS` writes every area out by hand because a row's label is a sentence ("Units, employees, deployments and tags"), not a name `Permission` holds. That makes the matrix a fourth copy of the permission set alongside the enum, the TS union and `use-can.ts`'s `implied` map — `tests/Unit/PermissionMatrixContractTest.php` parses the file and fails when a case has no row.

A View a Manage implies is checked and **`aria-disabled`, not `disabled`**: a disabled control leaves the tab order, and the lock icon beside the box is the explanation someone arriving by keyboard needs. `ui/checkbox.tsx` carries `aria-disabled:` variants mirroring its `disabled:` ones for exactly this. Only directly-held rights are submitted; the backend grants the implied view through `Permission::implies()`.

## Combobox, tag input and the z-index ladder overlays actually need
`components/combobox.tsx` is the design's answer for a choice too long for a `<Select>` — §5.14's popover with `cmdk` inside it. Two variants: `field` (a 36px control on a form) and `inline` (text until hovered, for a table cell like a unit's head, which needs `group/row` on the `<tr>`). Controlled by `value`/`onValueChange`; pass `name` and it writes a hidden input so an Inertia `<Form>` submits it with no state lifted into the page. It carries **no** `role="combobox"`: that role owes an `aria-controls` pointing at a listbox, and Radix wires `aria-haspopup="dialog"` on the trigger, so half-claiming it announces "combobox, collapsed" with nothing to expand. The popover's width is its content's, floored at the trigger and at 232, capped at 420 — tied to the trigger it inherited a 220px filter control and clipped every option.

`components/tag-input.tsx` is the multi-value field: Enter or a comma commits, Backspace in an empty box takes the last back, blur commits a half-typed tag, duplicates are refused silently. Values travel as hidden `tags[]` inputs, and an empty list sends none — the shape the Form Requests default to `[]`. The focus ring is on the box, not the inner input, because the field a reader sees is the box.

**Overlay z-index**: shadcn ships every overlay at a flat `z-50`, which is not §4.4's ladder — scrim 50, sheet 55, popover/menu/toast 60. MEASURED: with both at 50 the unit picker inside the move sheet rendered *behind* the sheet's surface. `popover.tsx` is now `z-[60]` and `sheet.tsx` `z-[55]`. Dialog, alert-dialog, dropdown-menu and select are still at 50 on purpose: their relative order is settled by Radix's portal DOM order (an alert dialog opened from a still-open row menu wins because it portals later), and raising the menu would break that.

## A nav group waits for a tenant that can hold its rows
The Organization group is gated on `agency !== null && !agency.platform` as well as on `organization.view`, and `tests/Unit/OrganizationNavContractTest.php` locks that from the suite.

Why the permission check is not enough: `SetTenant` defaults a platform user's tenant to the platform agency itself, `Gate::before` grants a superuser every ability, and `employees`/`units` carry an `agency_not_platform` trigger that raises P0001 — so Employees → Add employee from the platform tenant ends in an uncaught 500. Nothing in the permission layer sees that; what decides it is whether the agency they are in is a real one. Any future group whose rows the platform agency may not hold needs the same gate.

Also: only offer a row action that can succeed. Remove on a unit is rendered only when it has no children and no deployments at all (`deployments_count`, closed rows included) — `units.parent_id` and `deployments.unit_id` both RESTRICT, and that refusal arrives as a 500, not as a message a form can show. The row already shows the headcount and the indentation shows the children, so what stands in the way is on screen next to the absent item.
