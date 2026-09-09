---
paths:
  - 'resources/js/components/**'
---

# Components

## The shell: one sticky title bar, navigation as data, appearance in the user menu
Design source of record for every shell part: docs/design/08-interface.md plus docs/design/mockups/ (tokens.css, ui.css, the numbered artboards). The mockups win when they and the doc disagree.

`components/page-header.tsx` IS the sticky title bar (56 high, sticky top 0, z 30, `-mx-8 px-8` to escape the content column's padding). Every page in the shell renders it as its first child and passes `title`, an optional single-parent `breadcrumb` (§5.2 allows one level and no trailing chevron), an optional `description` for the line under the bar, `live` for the positive dot, and `actions` for the page's one primary action. Do not hand-roll a title row in a page, and do not add a fourth form — §5.2 allows three. The month stepper is the third and is unbuilt until a month-scoped page exists (Workdays, Milestone 6).

`app-layout.tsx` owns the scroll container: the content column scrolls, not the window. That is what makes both sticky layers work — the bar at `top: 0` and a table head at `top: var(--bar-h)` (56). The container carries `data-stuck` and the bar reads it through `group-data-[stuck=1]/scroll:`. A panel wrapping a table must NOT set `overflow: hidden` for its rounded corners: an `overflow` ancestor becomes the sticky scrollport and the head silently stops sticking.

Navigation is data, not markup. `app-sidebar.tsx` builds a `NavGroup[]` and `nav-main.tsx` renders it; a milestone adds a group to the array. Only what exists is rendered — a nav item or menu entry that leads nowhere is worse than an absent one. A count on a nav item means work is held up, not how many rows the page has. The group label is a `<div>` referenced by the list's `aria-labelledby`, never a heading: the sidebar precedes the page in the DOM, so a heading there lands above the page's own `<h1>`.

The user menu (`user-menu.tsx`) owns appearance. Light/Dark/System is the only appearance control in the product and it is wired to `hooks/use-appearance.ts`. Inside a Radix menu the segmented control must be `DropdownMenuSegmented` / `DropdownMenuSegmentedItem` (menuitemradio), not `toggle-group.tsx`: a menu swallows Tab and keeps focus in its own roving group, so plain buttons in the surface are unreachable by keyboard.

`hooks/use-manila-clock.ts` is the one clock. Asia/Manila through `Intl`, assembled from `formatToParts` (no locale prints what the artboards draw), ticking on the minute. A day runs 06:00 to 30:00, so the day strip's date is the open window's date, not the calendar date.
