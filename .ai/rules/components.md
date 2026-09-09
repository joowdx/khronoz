---
paths:
  - 'resources/js/components/**'
  - resources/js/components/permission-matrix.tsx
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
