# 08 — interface: tokens, type, components, patterns

Derived from the approved artboards in `mockups/`, committed at `0fcc57f`: `tokens.css` (four token sets), `ui.css` (every component's real CSS), `01-foundations.html` (the specimen sheet), and the five screens. `theme.js` is the review harness, not product code. Every number, hex and ratio below is read out of those files or measured; §11 records what was measured independently and lists every place the sources disagree with each other, with the ruling and the reason.

## 1. The language

White is the material. Hierarchy comes from type and rules, not from boxes: a section is a 14/600 head, a 1px rule and 32px of air either side of it, not a card. Every grey is a pure achromatic grey; the only hues on a screen are one accent, the eight-slot chip ramp and the fault, attention and positive families, and hue in the data is the point of the roster grid and the lane chart. One family, Plus Jakarta Sans, does the wordmark, the titles and the interface. The product is a working instrument for a Philippine HR office, so figures are tabular, controls are 36px with borders that clear 3:1, and nothing decorative competes with a number.

| # | Rule |
|---|---|
| 1 | The canvas is `--canvas`. No page-level tint, no dark shell block, no coloured header band. |
| 2 | A bordered 12px panel appears **only** where grouping needs a container: a table, a chart, a matrix. Users has exactly one panel; the dashboard has exactly one. |
| 3 | Sections are separated by `1px solid var(--line)` with 32px above and below. Never by a box. |
| 4 | Colour lives in the data (chip ramp, semantic families) and in one accent. Neutral surfaces carry no accent tint; the active nav item and a selected row do. |
| 5 | No shadow anywhere except the popover, the menu, the dialog and the sheet, all of which use the single `--lift`. |
| 6 | No gradients. The two `*-gradient()` uses are data or structure, not decoration: the Off-day `repeating-linear-gradient` hatch, and flat `linear-gradient` fills used to paint a 1px hairline inside a taller box. |
| 7 | No 3D, no bevel, no glass, no glow, no animation on a status indicator. |
| 8 | Sentence case throughout. No all-caps labels, no eyebrows, no letter-spaced small caps. |
| 9 | No middle-dot metadata (`Admin · Active · Today`). Metadata gets its own cell, its own line or its own right-aligned column. |
| 10 | No arrows in link text. "Go to ledgers", not "Go to ledgers →". A chevron is an icon in a row, never a character in a sentence. |
| 11 | Nothing is italic. `i`, `em`, `cite`, `var`, `address`, `dfn` are reset to `font-style: normal` and used as neutral inline boxes in the roster and the lane chart. |
| 12 | No monospace. Tabular figures come from the text face. |
| 13 | Right-align every column of figures; centre a figure that owns its own fixed cell (roster day header, axis label, day strip). |
| 14 | Icons are 16×16 on a 16 viewBox, `fill="none"`, `stroke="currentColor"`, `stroke-width="1.5"`, round caps and joins. `--muted` at rest, they take the row's colour when the row is active. |
| 15 | The mark is three bars on a baseline (34×24 viewBox, 1.6 stroke, bars filled `currentColor`) in `--acc` or `--acc-text`; the wordmark beside it is `khronoz`, lowercase, 700, in `--ink`. |

## 2. Tokens

Four sets: `[data-accent="violet"|"blue"]` × `[data-mode="light"|"dark"]`. Bare `:root` is violet + light, so the page is correct with no attributes and no JavaScript.

### 2.1 Rules that govern the token file

| # | Rule |
|---|---|
| 1 | **Neutrals are pure achromatic.** R = G = B on every neutral, in both modes and under both accents. A neutral with a hue is a bug. |
| 2 | **`--edge` is deliberately darker than `--line`.** `--line` (1.26 : 1) draws panel borders and section rules, which are decoration and exempt. `--edge` (3.45 : 1) draws control borders, which are the *only* thing that says where a control begins, so WCAG 2.2 1.4.11 requires 3:1 against the adjacent surface. Neither a shadow nor a hairline can satisfy that, so this product buys it with a genuinely darker border. Any implementation that ties the two (shadcn does) drops every control border below 3:1. |
| 3 | Control borders clear 3:1 on the canvas **and** on the sidebar, whose ground is already `#F5F5F5`. Every text pair clears 4.5:1 on both grounds. |
| 4 | Two greys do the hover work because one cannot: `--row-hover` on the canvas, `--side-hover` on the sidebar. |
| 5 | One shadow token, `--lift`, and one scrim token, `--scrim`. |
| 6 | `color-scheme` is declared per mode so form controls, scrollbars and the caret follow. |

### 2.2 Neutrals

Ratios are contrast against the canvas / against the sidebar, in that mode. `—` means the token is a ground or a fill that carries no text of its own.

| Token | Light | Dark | Role | Light ratio | Dark ratio |
|---|---|---|---|---|---|
| `--canvas` | `#FFFFFF` | `#0A0A0A` | page ground | — | — |
| `--side` | `#F5F5F5` | `#171717` | sidebar and rail ground | — | — |
| `--sheet` | `#FFFFFF` | `#171717` | sheet, popover, menu, dialog surface | — | — |
| `--line` | `#E5E5E5` | `#262626` | panel border, section rule, thead rule | 1.26 / 1.16 | 1.31 / 1.18 |
| `--rule` | `#F0F0F0` | `#1F1F1F` | soft row divider; also the meter trough and the lane centre line | 1.14 / 1.05 | 1.20 / 1.09 |
| `--edge` | `#8A8A8A` | `#737373` | **control border**, dashed Remote cell, switch knob at rest | 3.45 / 3.17 | 4.18 / 3.78 |
| `--edge-soft` | `#D4D4D4` | `#333333` | non-essential inner divider, keycap border, chart baseline, midnight dots | 1.48 / 1.36 | 1.57 / 1.42 |
| `--ink` | `#171717` | `#EDEDED` | primary text; tooltip ground | 17.93 / 16.44 | 16.91 / 15.31 |
| `--muted` | `#6B6B6B` | `#A3A3A3` | secondary text, column heads, icons at rest, major axis tick | 5.33 / 4.89 | 7.85 / 7.11 |
| `--row-hover` | `#F5F5F5` | `#1F1F1F` | row and control hover on the canvas; roster group row | — | — |
| `--side-hover` | `#EAEAEA` | `#262626` | hover on the sidebar and rail | — | — |
| `--fault` | `#B91C1C` | `#F87171` | error text, error border, destructive action | 6.47 / 5.93 | 7.16 / 6.48 |
| `--fault-soft` | `#FEE2E2` | `#3A1A1A` | error banner and pill ground | — | — |
| `--attn` | `#B45309` | `#FBBF24` | attention text, suspension day header, legend note | 5.02 / 4.61 | 11.86 / 10.74 |
| `--attn-soft` | `#FEF3C7` | `#3A2C0F` | attention banner and pill ground; suspension day wash | — | — |
| `--pos` | `#047857` | `#34D399` | positive text, live dot | 5.48 / 5.03 | 10.30 / 9.33 |
| `--pos-soft` | `#D1FAE5` | `#0F2E25` | positive pill ground | — | — |
| `--weekend` | `#FAFAFA` | `#141414` | weekend column wash | — | — |
| `--off-fill` | `#FAFAFA` | `#141414` | Off-day cell ground under the hatch | — | — |
| `--off-hatch` | `#D4D4D4` | `#333333` | Off-day hatch stroke and legend swatch border | — | — |
| `--tick` | `#8E8E8E` | `#666666` | minor axis tick, a graphical object under 1.4.11 | 3.28 / 3.01 | 3.45 / 3.12 |
| `--dot` | `#C4C4C4` | `#3A3A3A` | leader dots, decorative | — | — |
| `--scrim` | `rgba(0,0,0,.28)` | `rgba(0,0,0,.60)` | behind the sheet and the dialog | — | — |
| `--lift` | `0 8px 24px rgba(0,0,0,.10)` | `0 8px 24px rgba(0,0,0,.55)` | the only shadow | — | — |

### 2.3 Accent, violet (shipped)

| Token | Light | Dark | Role | Ratio, light / dark |
|---|---|---|---|---|
| `--acc` | `#6D28D9` | `#7C3AED` | primary fill, focus ring, now-line, today circle | white on it: 7.10 / 5.70 |
| `--acc-hover` | `#5B21B6` | `#6D28D9` | primary fill on hover | white on it: 8.98 / 7.10 |
| `--acc-ink` | `#FFFFFF` | `#FFFFFF` | text and knob on an accent fill | — |
| `--acc-text` | `#6D28D9` | `#A78BFA` | link, active nav label, sort glyph, meter bar | canvas 7.10 / 7.27 · sidebar 6.52 / 6.59 · on `--acc-soft` 5.98 / 5.54 |
| `--acc-soft` | `#EDE9FE` | `#2A1F4A` | active nav ground, agency tile, pill, now label | — |
| `--acc-tint` | `#F5F3FF` | `#1B1730` | selected table row | `--ink` on it 16.35 / 14.80 · `--muted` 4.86 / 6.87 · `--acc-text` 6.48 / 6.37 |

### 2.4 Accent, blue (switchable, not exposed in v1)

| Token | Light | Dark | Ratio, light / dark |
|---|---|---|---|
| `--acc` | `#2563EB` | `#3B6FE0` | white on it: 5.17 / 4.63 |
| `--acc-hover` | `#1D4ED8` | `#2E5FCD` | white on it: 6.70 / 5.78 |
| `--acc-ink` | `#FFFFFF` | `#FFFFFF` | — |
| `--acc-text` | `#1D4ED8` | `#93C5FD` | canvas 6.70 / 10.98 · sidebar 6.15 / 9.94 · on `--acc-soft` 5.49 / 7.23 |
| `--acc-soft` | `#DBEAFE` | `#16305C` | — |
| `--acc-tint` | `#EFF6FF` | `#101B2E` | `--ink` on it 16.47 / 14.72 · `--muted` 4.90 / 6.83 · `--acc-text` 6.16 / 9.56 |

Verification: every ratio in §2.2–2.4 was recomputed from the hex values with the WCAG 2.x relative-luminance formula and reproduces `tokens.css` exactly, to the stated two decimals. See §11 for the one neutral where `tokens.css` and the foundations sheet disagree.

## 3. Type

One family. `--display` and `--text` point at the same stack; `--display` marks the display sizes so their tracking is tuned in one place.

```css
--display: "Plus Jakarta Sans", "Segoe UI", system-ui, -apple-system, sans-serif;
--text:    "Plus Jakarta Sans", "Segoe UI", system-ui, -apple-system, sans-serif;
```

One request, five weights, from Bunny:

```html
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800">
```

Weights in use: 400 body, 500 emphasis and interface, 600 section heads and figures in rows, 700 titles and the wordmark. 800 is loaded and unused by the product; it exists for the marketing page. Negative tracking starts at 20px and grows with the size, because the face opens up as it gets larger.

### 3.1 The scale

| Role | Size / leading | Weight | Tracking | Selector, and the one job it does |
|---|---|---|---|---|
| Home headline | 56 / 59 | 700 | −0.02em | marketing home only |
| Wordmark | 36 / 40 | 700 | −0.022em | `.brand h1`, the sign-in band |
| Month title | 32 / 38 | 700 | −0.018em | `.month` — the title on month-scoped pages, `tabular-nums` |
| Page title | 24 / 30 | 700 | −0.011em | `.ptitle` — every other page's title |
| Figure | 24 / 30 | 700 | −0.011em | `.figs .fv` — a headline number, `tabular-nums` |
| Auth heading | 24 / 30 | 600 | −0.008em | `.fc h2` — the sign-in form heading only |
| Sheet title | 20 / 26 | 700 | −0.008em | `.sheet-t` |
| Empty-state heading | 20 / 26 | 700 | −0.008em | `.empty h2` |
| Nav-row figure | 18 / 24 | 600 | — | `.kv--nav .v` — the count a row navigates to |
| Auth promise | 16 / 26 | 400 | — | `.promise` — the one line under the wordmark |
| Body, inputs, cells | 14 / 20 | 400 | — | `body`, `.inp`, `.tb td`, `.kv .k`, `.cbl` |
| Emphasis | 14 / 20 | 500 | — | a person's name in a row |
| Section head | 14 / 20 | 600 | −0.002em | `.sec-t`; `.kv .v` at 600 is the same step |
| Interface, secondary | 13 / 18 | 500 | — | `.nav-item`, `.btn`, `.sel`, `.field-hd label`, `.crumb`, `.pop > *` |
| Field error | 13 / 18 | 500 | — | `.field-hd .err`, `--fault`, right-aligned |
| Lede, hint under a title | 13 / 18 | 400 | — | `.sub`, `.figs .fl` |
| Column head, pill, meta | 12 / 16 | 600 heads, 500 pills, 400 meta | — | `.tb th`, `.pill`, `.sec-m`, `.ds-*`, `.panel-ft` |
| Hint under a field | 12 / 16 | 400 | — | `.hint` |
| Brand footer | 12 / 16 | 700 | −0.004em | `.brand-foot` |
| Chip letter, bar label, axis label | 11 / 14 | 600 | — | `.chip`, `.bar`, `.nb`, `.axis .lb`, `.nav-n`, `tabular-nums` |
| Keycap, roster weekday, employee number | 11 / 14 | 500 | — | `.key`, `.rg-hd .d em`, `.rg-row .emp .no` |
| Day-strip label, cycle-preview number | 10 / 13–14 | 500 | — | `.ds-lb`, `.cycle-n span` |

### 3.2 Tabular figures

`font-variant-numeric: tabular-nums` is declared on `body` and belongs on every time, date, count and minute figure. It is not decorative and it is not a no-op.

Verified three ways on the Bunny-served faces:

| Check | Result |
|---|---|
| GSUB feature list, `plus-jakarta-sans-latin-400-normal.woff2` and `-600-` | `calt ccmp dnom frac liga locl numr pnum tnum` — `tnum` is present |
| `tnum` lookup (single substitution) | maps `zero…nine` → `zero.tf…nine.tf` |
| Advance widths, 1000 upm | default digits 371–732 (proportional); every `.tf` digit exactly 600 |
| Headless Chromium, 100px, ten digits | proportional `1111111111` = 371px, `0000000000` = 732px; with `tabular-nums` both are exactly 600px |

The claim in `ui.css`'s `body` comment and in the foundations sheet's fallback-stack note — that the face ships no tabular-figure feature — is false and must not be carried into the implementation. Structural right-alignment (§1 rule 13) stays as belt-and-braces so a fallback face cannot go ragged; it is no longer the mechanism.

## 4. Space, radius, dimension

### 4.1 Spacing scale

| Token | px | What it is for |
|---|---|---|
| `--s1` | 4 | icon-to-label nudge, sub-label offset |
| `--s2` | 8 | inside a control, between a label and its field |
| `--s3` | 12 | table cell padding, gap between controls in a row |
| `--s4` | 16 | sidebar block padding, gap inside a banner group |
| `--s5` | 24 | panel gutter (`.tb` first/last cell), gap between fields |
| `--s6` | 32 | page padding, section gap either side of the rule, top margin of a field group |
| `--s7` | 48 | bottom padding of a page, gutter between split columns, empty-state padding |

Panel padding is 20 (`.panel-pad`), which sits between `--s5` and `--s6` and is the one padding not on the scale.

### 4.2 Radius

| Level | Token | px | Applies to |
|---|---|---|---|
| Panel | `--r-panel` | 12 | `.panel`, `.card`, the review frame |
| Control | `--r-ctl` | 8 | button, input, select, nav item, agency tile, segmented shell, banner, sheet field |
| Chip, badge | `--r-chip` | 6 | `.chip`, `.bar`, `.nb`, `.rem`, legend swatches, tooltip |
| Pill | `--r-pill` | 999 | `.pill`, `.nav-n`, `.nowlbl`, the switch track |
| Circle | — | 50% | avatar, live dot, switch knob, today's date |

Measured exceptions, to be reproduced as they are: popover and menu 10; checkbox 5; keycap 5; segmented inner button 6; meter bar and event key square 2.

### 4.3 Fixed dimensions

| Thing | px | Token |
|---|---|---|
| Sidebar | 248 | `--side-w` |
| Rail (roster) | 64 | `--rail-w` |
| Sticky title bar | 56 | `--bar-h` |
| Table row | 44 | `--row-h` |
| Two-line table row | 52 | `.tb--52 td` |
| Table head | 36 | `.tb th` |
| Control, default | 36 | `--ctl-h` |
| Control, compact (sidebar search, icon button, segmented) | 32 | `.inp--32`, `.btn--32`, `.seg` |
| Control, mobile and prominent | 44 | `.inp--44`, `.btn--44` |
| Sheet | 420 | `.sheet` |
| Form column, invite | 560 | `.form` |
| Form column, sign-in | 400 | `.fc` |
| Popover, minimum | 232 | `.pop` |
| Popover, user menu | 268 | `.pop--user` |
| Popover, row menu | 252 | `04-users.html` |
| Roster day column | 27 | `.rg-hd .d`, `.rg-row .c` |
| Roster data row | 34 | `.rg-row` |
| Roster group row | 28 | `.rg-grp` |
| Roster head | 38 | `.rg-hd` |
| Roster frozen columns | 200 + 120 | `.emp` + `.sch` |
| Roster chip | 21 × 22 | `.rg-row .c .chip` |
| Panel header | 52 min | `.panel-hd` (60 when it carries filters) |
| Panel footer | 48 min | `.panel-ft` |
| Lane row | 32, bar 24 | `.lane`, `.bar` |
| Lane label / count gutters | 92 / 52 | `.lane .ln` / `.lane .cn` |
| Avatar | 32, 28, 24 | `.avatar`, `--28`, `--24` |
| Checkbox | 18 visible, 28 hit | `.cb` + its `::before` inset −5 |
| Switch | 34 × 20, knob 14 | `.sw` |
| Status pill | 22 high | `.pill` |
| Nav item | 32 high | `.nav-item` |
| Sidebar user menu item | 40 high | `.userbtn` |
| Empty state | 460 max-width | `.empty` |

### 4.4 Stacking

One z-index ladder, page-wide.

| Layer | z-index |
|---|---|
| Roster wash | 0 |
| Roster data row | 1 |
| Roster group row | 2 |
| Roster frozen cell (row) | 4 |
| Roster night band | 5 |
| Roster now-line | 6 |
| Table head (sticky) | 10 |
| Roster head (sticky) | 20 |
| Roster frozen cell (head) | 22 |
| Sticky title bar | 30 |
| Rail tooltip | 40 |
| Scrim | 50 |
| Sheet | 55 |
| Popover, menu, toast | 60 |

## 5. Components

Every component's default is the token set; only the deltas are listed. "Focus" everywhere means the global rule unless a component overrides it:

```css
:where(a, button, input, select, [tabindex]):focus-visible {
  outline: 2px solid var(--acc); outline-offset: 2px; border-radius: var(--r-ctl);
}
```

A field overrides the offset to `-1px` so the ring sits inside its own border and cannot be clipped by a panel edge, and turns `--fault` when the field is invalid.

### 5.1 Panel

| Aspect | Spec |
|---|---|
| Shell | `1px solid var(--line)`, radius 12, background `--canvas`. No shadow, ever. |
| Header | min-height 52, padding 12 / 24, `1px solid var(--line)` bottom. Title 14/600 left, meta or count 12/16 muted right, filters between. 60 min-height when it carries filter controls. |
| Body | either a flush `.tb` or `.panel-pad` (20). |
| Footer | min-height 48, padding 12 / 24, `1px solid var(--line)` top, 13/18 muted, `tabular-nums`. Range on the left, pager buttons on the right. |
| When to use | only where grouping needs a container: a table, a chart, a matrix. Not for a stat, not for a form field, not for a list of rows. |
| States | none. A panel is not interactive. |
| A11y | `<section>` with an `aria-labelledby` pointing at the header title. The panel border is decoration and is exempt from 1.4.11. |

### 5.2 Sticky title bar

Height 56, `position: sticky; top: 0`, z-index 30, padding `0 32`, background `--canvas`, `border-bottom: 1px solid transparent`. The rule appears only once the scroller has moved: the scroll container carries `data-stuck="1"` and the bar's `border-bottom-color` becomes `--line`. Three forms, no fourth.

| Form | Left | Used on |
|---|---|---|
| Plain title | `.ptitle` 24/30/700 | Users, Employees, every list without depth |
| Title with breadcrumb | `.crumb` (13/18/500 muted link + a 14px chevron in `--edge`) then `.ptitle`, aligned on one baseline row | Invite user, any page one level down |
| Month stepper as title | 32px icon button, `.month` 32/38/700 `tabular-nums`, 32px icon button | Dashboard, Roster, Workdays, Ledgers — month-scoped pages only |

Right side: `.spacer` then the page's one primary action, plus scoped filters (`.sel`) to its left on the roster.

| Aspect | Spec |
|---|---|
| A11y | `<header>` containing `<h1>`. The month stepper's buttons are real `<button>`s with `aria-label="Previous month"` / `"Next month"`; the month text is the `<h1>`, so a screen reader reads "September 2026, heading level 1". Changing month re-renders the page; announce the new month by moving focus to the `<h1>` or via a polite live region — never by an alert. |
| Breadcrumb | the chevron is `aria-hidden`; the parent is a real link. No trailing chevron after the current page. |

### 5.3 Sidebar

248 wide, ground `--side`, `border-right: 1px solid var(--line)`, a flex column. Pure grey — the sidebar itself never takes an accent tint.

| Part | Spec |
|---|---|
| Agency block | padding `16 16 0`, gap 10. 32×32 tile, radius 8, `--acc-soft` ground, `--acc-text` initials at 11/14/600, tracking 0.01em. Name 14/18/600; below it the headcount at 12/16/400 muted, `tabular-nums`. |
| Search | padding `14 16 0`. 32px field on `--canvas` (lighter than its ground, so it reads as a field), search icon at left 11, keycap at right 6: 18 high, padding `0 5`, radius 5, `1px solid var(--edge-soft)`, 11/16/500 muted, `pointer-events: none`. |
| Day strip | padding `14 16 2`. Header row is baseline space-between: day name 12/16/500, "147 on duty" 12/16/400 muted. Axis 13px tall with hour ticks; a 1px `--acc` now-line; label row 14px at 10/14/500 muted with `06` pinned left, `30` pinned right and the current time centred on the line at 600 `--acc-text`. The scale is the product's one time scale: a day opens at 06:00 and closes at 30:00. |
| Nav | `flex: 1`, own scroller, padding `12 12 16`. |
| Group label | padding `14 8 6`, 12/16/500 muted. Groups: Organization, Scheduling, Daily time records. Dashboard, Users and Settings are ungrouped. |
| Nav item | 32 high, padding `0 10`, gap 10, radius 8, 13/18/500 `--ink`, icon `--muted`. Hover `--side-hover`. Current: `aria-current="page"` → `--acc-soft` ground, `--acc-text` label and icon, weight 600. |
| Count on a nav item | `.nav-n`: min-width 20, height 18, pill, padding `0 6`, 11/14/600 `tabular-nums`, `--rule` on `--muted`. The attention variant is `--attn-soft` on `--attn`, used when the count is work that is held up. |
| Footer | `border-top: 1px solid var(--line)`, padding `8 10`. The user menu item is a 40px `<button>`, not an icon: 28px avatar, name 13/17/500, address 12/16 muted, both truncated with an ellipsis, chevron `--muted` at the right. Hover and `aria-expanded="true"` both take `--side-hover`. |
| Brand footer | padding `0 18 14`, `khronoz` at 12/16/700, tracking −0.004em, `--muted`. |

| Aspect | Spec |
|---|---|
| A11y | `<nav aria-label="Main">` with `<ul>`/`<li>`; the group label is the `<li>`'s own heading or an `aria-labelledby` on a nested `<ul>`. The current item carries `aria-current="page"` — that, not colour, is what is announced. A count reads as part of the link's accessible name: `Workdays, 24 need attention`, supplied by a visually hidden span, because "24" alone is meaningless in the accessibility tree. The user menu button carries `aria-haspopup="menu"` and `aria-expanded`. |

### 5.4 Collapsed rail

64 wide, ground `--side`, `border-right: 1px solid var(--line)`, `align-items: center`, padding `16 0 12`. Used on the roster so the timetable owns the width.

| Part | Spec |
|---|---|
| Agency | the 32px tile alone. |
| Search | collapses to a 36px icon button; the shortcut moves into the tooltip. |
| Item | 36×36, radius 8, icon-only, `--muted`; hover `--side-hover` + `--ink`; current `--acc-soft` + `--acc-text`. |
| Separator | 1px `--line`, margin `8 8`, in place of a group label. |
| Tooltip | absolute at `left: 44`, vertically centred, `--ink` ground, `--canvas` text, radius 6, padding `4 8`, 12/16/500, `opacity` 0 → 1 on hover, z-index 40. A shortcut inside it is `--edge`. `.tip.left` flips it to `right: 44`. |
| Footer | the 40×40 avatar alone is the user-menu trigger. |

| Aspect | Spec |
|---|---|
| A11y | every rail item needs an `aria-label`, because the tooltip is decoration and disappears on keyboard focus in the mockup. Give the tooltip `role="tooltip"` and reference it with `aria-describedby`, and show it on `:focus-visible` as well as `:hover` — an icon-only nav that only labels itself on mouse hover fails 1.4.13 and 4.1.2. |

### 5.5 Button

36 high (44 on mobile), padding `0 14`, radius 8, gap 7, 13/18, `white-space: nowrap`.

| Variant | Rest | Hover | Notes |
|---|---|---|---|
| Primary | `--acc` fill and border, `--acc-ink` text, weight 600 | `--acc-hover` fill and border | one per page, in the title bar or under a form |
| Secondary | `--canvas`, `1px solid var(--edge)`, `--ink`, weight 500 | `--row-hover` | the default button |
| Ghost | transparent fill and border | `--row-hover` | Cancel, icon buttons, pager |
| Destructive | transparent, `--fault` text | `--fault-soft` | never a filled red button; the confirmation dialog carries the weight |

| State | Spec |
|---|---|
| Focus | global ring: 2px `--acc`, offset 2 |
| Pressed | no separate treatment; hover stands in. A toggle uses the segmented control instead. |
| Disabled | `opacity: .45`, `cursor: not-allowed`, hover suppressed (`.btn[disabled]:hover` restores the rest background) |
| Loading | keep the label, swap the leading icon for a spinner, set `aria-busy="true"` and `disabled`. Never change the width. |
| Sizes | `--icon` = square at 36; `--32` = square at 32 (pager, close, month stepper); `--44` = 44 high at 14/20; `--full` = 100% width (sign-in, mobile) |
| A11y | a real `<button type="button">`. An icon-only button needs `aria-label`. Its icon is `aria-hidden="true"`. A destructive button's label names the object: "Cancel invitation", not "Cancel". |

Link: `.lnk` is `--acc-text` at 500, no underline at rest, underline with `text-underline-offset: 2px` on hover. Inside a table cell it takes `padding: 3px 0` so its hit area reaches 24px.

### 5.6 Input

Full width, 36 high (44 with `--44`), padding `0 12`, radius 8, `1px solid var(--edge)`, `--canvas` ground, 14/20.

| State | Spec |
|---|---|
| Placeholder | `--muted` |
| Hover | `border-color: var(--ink)` |
| Focus | `outline: 2px solid var(--acc); outline-offset: -1px; border-color: var(--acc)` |
| Error | `[aria-invalid="true"]` → `border-color: var(--fault)`; focused, both ring and border are `--fault` |
| Disabled | `--rule` ground, `--muted` text, `--edge-soft` border |
| With icon | wrap in `.inp-wrap` (`position: relative`), icon absolute at `left: 11`, field `padding-left: 34` (32 at the 32px size) |
| A11y | a real `<label for>`; never a placeholder as the label. Error state is `aria-invalid="true"` plus `aria-describedby` pointing at the error text and, if present, the form-level banner (space-separated ids). A screen reader then reads "Email, edit text, invalid entry, Already taken". |

### 5.7 Select

Rendered as a `<button>` with a chevron, not a native `<select>`, because it opens a popover menu.

| Aspect | Spec |
|---|---|
| Shell | inline-flex, 36 high, padding `0 34 0 12`, radius 8, `1px solid var(--edge)`, 13/18/500, chevron absolute at `right: 11` in `--muted` |
| Label inside | the field name sits in the control at 400 `--muted` (`Access` `Any`); the value follows at 500 `--ink` |
| Hover | `border-color: var(--ink)` |
| Focus | global ring |
| Full width | `width: 100%; justify-content: space-between` (used in the sheet) |
| States | error and disabled follow the input's rules; the mockups do not draw them, so reuse `--fault` border and the input's disabled palette |
| A11y | `aria-haspopup="listbox"`, `aria-expanded`, and a `role="listbox"` popover whose options are `role="option"` with `aria-selected`. Up/Down move, Enter and Space choose, Escape closes and returns focus, typing jumps. If a native `<select>` is used instead, it must be restyled to the same box; do not ship both. |

### 5.8 Checkbox

18×18 visible, radius 5, `1px solid var(--edge)`, `--canvas`. The hit area is grown to 28×28 by a `::before` at `inset: -5px`, so the 24px target of WCAG 2.2 2.5.8 is met without redrawing the box.

| State | Spec |
|---|---|
| Unchecked | as above, glyph `color: transparent` |
| Hover | `border-color: var(--ink)` |
| Checked | `--acc` fill and border, `--acc-ink` check |
| Focus | global ring, offset 2 |
| Error | `.cb--err` → `border-color: var(--fault)`; focused, the ring is `--fault` too |
| Disabled, unchecked | `--rule` ground, `--edge-soft` border |
| Disabled, checked (implied) | `--edge-soft` ground, `--edge` border, `--ink` check — measured 12.09:1 light and 10.79:1 dark — **plus a 14px lock icon beside it**. This is the permission matrix's "a manage right implies its view right" state; the lock, not the grey, is what carries the meaning. |
| Label | `.cbl`: inline-flex, gap 9, 14/20, the whole label clickable |
| A11y | prefer a native `<input type="checkbox">`. Where a `<span role="checkbox">` is used (as in the matrix) it needs `tabindex="0"`, `aria-checked`, `aria-disabled` and an `aria-label` that names the permission string, and Space must toggle it. An implied-and-locked box announces "organization.view, implied by manage, checked, dimmed". |

### 5.9 Switch

34×20 track, radius 999, `1px solid var(--edge)`, `--canvas`; a 14px round knob in `--edge` at `left: 2`. Checked: `--acc` track and border, knob `--acc-ink` at `left: 16`. Same 28px grown hit area as the checkbox. Focus is the global ring.

Use for an immediate setting that takes effect on toggle. Use a checkbox for something a form submits. A11y: native `<input type="checkbox" role="switch">` or `role="switch"` with `aria-checked`; the label sits outside and is not the state ("Night differential", not "Night differential on").

### 5.10 Segmented control

32 high, padding 2, radius 8, `1px solid var(--edge)`, `--canvas`. Buttons 26 high, padding `0 11`, radius 6, transparent, 12/16/500 `--muted`; hover takes `--ink`; selected takes `--acc-soft` ground, `--acc-text`, weight 600. `.seg--full` stretches each button to an equal share (the appearance switcher in the user menu).

Two uses only: a preset chooser (Admin / HR officer / Viewer / Custom) and a three-way appearance choice (Light / Dark / System).

A11y: `<span class="seg" role="group" aria-label="...">` wrapping real `<button type="button">`s with `aria-pressed`. Arrow keys are not required for a group of buttons; Tab reaches each. If it is modelled as a radio group instead, use `role="radiogroup"` + `role="radio"` + `aria-checked` and then arrow keys **are** required.

### 5.11 Badge and status pill

`.pill`: 22 high, padding `0 9`, gap 6, radius 999, 12/16/500, `nowrap`. An optional 5px `currentColor` dot leads.

| Variant | Ground / text | Meaning |
|---|---|---|
| `--ok` | `--pos-soft` / `--pos` | Active, Locked, Attested |
| `--att` | `--attn-soft` / `--attn` | Invited, Waiting for lock, Needs review |
| `--bad` | `--fault-soft` / `--fault` | Locked out, Failed |
| `--neutral` | `--rule` / `--muted` | Not enrolled, Off, None |
| `--acc` | `--acc-soft` / `--acc-text` | a time or a count worth pointing at (`14:42`) |

A pill states a fact and is never a control. The dot is decoration (`aria-hidden`); the word is the state, so colour is never the only cue. A11y: plain text in a `<span>`. If it changes without a page load, put it in a `aria-live="polite"` region.

### 5.12 Avatar

32px circle (28 and 24 variants), initials at 12/16/600 with 0.01em tracking. Ground and text come from the chip ramp by slot: `.a1`…`.a8` use `--cN-fill` on `--cN-text`, the same eight pairs as the chips, so an avatar's tint carries no separate palette. Assign the slot from the person's name, deterministically, so the same person is the same colour on every screen. Never a photo in v1. A11y: `aria-hidden="true"` when the name is beside it, which it always is in a row; give it `role="img"` and an `aria-label` only when it stands alone.

### 5.13 Table

`.tb` is `border-collapse: separate; border-spacing: 0` so sticky heads and frozen columns work.

| Part | Spec |
|---|---|
| Head | `<th>` 36 high, padding `0 12`, left-aligned, 12/16/600 `--muted`, `nowrap`, `border-bottom: 1px solid var(--line)`. Sticky: `position: sticky; top: var(--bar-h)` (56, under the title bar), z-index 10, and **the background sits on the `th`, not the `thead`** — a `thead` background does not paint under a sticky cell. `.tb--flat th { position: static }` for a table inside a scroll-free section. |
| Sortable head | a real `<button>` inside the `<th>`: inline-flex, 36 high, no padding, transparent, `color: inherit`, inherits the head's type. Its 8px caret is `opacity: 0` at rest, 1 on hover in `--edge`, and 1 in `--acc-text` when the column is sorted. `[aria-sort="descending"]` rotates the caret 180°. `.num > button` reverses the flex direction so the caret sits left of a right-aligned label. |
| Row | `<td>` 44 high (`.tb--52 td` = 52 for a two-line name cell), padding `0 12`, 14/20, `vertical-align: middle`, `border-bottom: 1px solid var(--rule)`. The last row drops its border. |
| Gutter | first and last cell take `padding-left`/`padding-right: 24` so content clears the panel edge; `.tb--flat` drops both to 0. |
| Numeric | `.num` → `text-align: right; font-variant-numeric: tabular-nums` |
| Hover | `tbody tr:hover td { background: var(--row-hover) }` |
| Selected | `tr[aria-selected="true"] td { background: var(--acc-tint) }` |
| Locked | `tr.locked td { color: var(--muted) }` — a locked ledger row drops its ink and keeps its pill, so the state is legible without colour alone |
| Two-line cell | `.who2`: avatar + name 14/18/500 over address 12/16 muted |
| Empty | replace `tbody` with the empty state (§5.20) inside the panel body; keep the head only if filters can bring rows back |
| Loading | keep the head and the row count, render skeleton rows at the same 44 height, set `aria-busy="true"` on the table |
| Frozen columns | `position: sticky; left: 0` (and `left: 200` for the second), and **an opaque background at rest and on hover** — `background: var(--canvas)` plus a `:hover` override to `var(--row-hover)`. Without the hover override the body's hover tint slides under the frozen cell and the text sits on two colours. |
| A11y | one `<caption>` or an `aria-labelledby` to the panel title. `aria-sort` on the sorted `<th>` (`ascending` / `descending`), and the inner `<button>` is what makes it keyboard operable — `aria-sort` alone is announced but cannot be activated (2.1.1). Row selection is `aria-selected` on `<tr>` plus a real checkbox in the row when selection is multi. Pager buttons carry `aria-label="Previous page"` / `"Next page"` and the footer range text is the live status. |

### 5.14 Popover and menu

`position: absolute`, min-width 232, padding 6, radius 10, `1px solid var(--line)`, `--sheet` ground, `box-shadow: var(--lift)`, z-index 60.

| Part | Spec |
|---|---|
| Item | 32 high, padding `0 9`, gap 9, radius 6, transparent, 13/18/500 `--ink`; icon `--muted`; hover `--row-hover` |
| Separator | 1px `--line`, margin `5 4` |
| Destructive item | `.fault` → text and icon in `--fault` |
| User menu | `.pop--user` at 268 wide: an identity block (28px avatar, name 13/17/600, address 12/16 muted, both truncated) with a `--line` bottom rule, then Account settings, then an Appearance block holding a full-width segmented control, then a rule, then Sign out |
| Row menu | 252 wide, anchored to the row's `⋯` button |
| Placement | **the popover is a child of the positioned app root, a sibling of the shell — not of the scroll container.** In the mockups both the row menu and the sheet close out of `.scroll` entirely. An overlay inside a scroller scrolls away from its trigger and is clipped by `overflow: auto`. |
| A11y | trigger has `aria-haspopup="menu"` and `aria-expanded`. The surface is `role="menu"`; items are `role="menuitem"` (real `<button>`s). Roving `tabindex`, Up/Down to move, Home/End to jump, Enter or Space to activate, Escape to close and return focus to the trigger, typing to jump by first letter. Focus is trapped only while open, and the trigger keeps its own focus ring. |

### 5.15 Sheet

`position: absolute; top 0 right 0 bottom 0`, width 420, z-index 55, `--sheet` ground, `border-left: 1px solid var(--line)`, `box-shadow: var(--lift)`. A `.scrim` at z-index 50 covers the app root behind it.

| Part | Spec |
|---|---|
| Header | padding `18 20`, `1px solid var(--line)` bottom. `.sheet-t` 20/26/700 −0.008em, spacer, then a 32px ghost close button with `aria-label="Close"` |
| Body | `flex: 1`, own scroller, padding 20. Fields stack at 16 apart (`.sheet-fld + .sheet-fld`) |
| Footer | padding `14 20`, `1px solid var(--line)` top, primary then ghost Cancel |
| Use | a focused task against context that must stay visible — Assign schedule over the roster grid it changes |
| A11y | `<aside role="dialog" aria-modal="true" aria-labelledby>` pointing at the title. Focus moves into the sheet on open and is trapped; Escape closes; focus returns to the trigger. The scrim is click-to-dismiss and `aria-hidden`. Content behind it is `inert`. |

### 5.16 Dialog

The mockups draw no centred dialog, so it is specified as the sheet's primitives re-laid: `--sheet` ground, `1px solid var(--line)`, radius 12, `box-shadow: var(--lift)`, over `--scrim` at z-index 50, dialog at 55. Width 420, padding 20, title at the sheet-title step (20/26/700 −0.008em), body 14/20, actions right-aligned at the bottom with 12 between them.

Reserved for one job: confirming a destructive or irreversible action — cancel an invitation, delete an agency, unlock a ledger that has attestations. The title asks the question, the body names the consequence in one sentence, the confirm button repeats the verb and the object ("Cancel invitation"), and the confirm button is the destructive variant, not a filled red block. Same dialog a11y contract as the sheet; focus starts on the safe action.

### 5.17 Banner

Flat, inside the content, never floating. `display: flex; gap: 10`, padding `11 13`, radius 8, `--fault-soft` ground, `--ink` text at 13/19, with a 16px alert icon in `--fault` offset `margin-top: 2`. `.banner--att` swaps the ground to `--attn-soft` and the icon to `--attn`. `.banner--field` adds `margin-top: 8` so it can sit directly under the field it belongs to.

Content is one sentence plus the fix, and the fix is a `.lnk` inside the sentence. No dismiss button — a banner describes a condition, and the condition is what removes it.

A11y: `role="alert"` when it appears in response to a submit (sign-in rejected); a plain `<div>` referenced by the field's `aria-describedby` when it is a field's explanation. Never both on the same node.

### 5.18 Toast

Not drawn in the mockups. Build it from the popover's primitives: `--sheet` ground, `1px solid var(--line)`, radius 10, `box-shadow: var(--lift)`, z-index 60, padding `11 13`, 13/18, fixed bottom-right of the app root with 16 of inset, max-width 360.

| Rule | Spec |
|---|---|
| Copy | named after the button that caused it, in the past tense: "Invitation sent", "Schedule assigned", "Ledger locked" |
| Colour | neutral surface. A leading 16px icon in `--pos` for success, `--fault` for a failed background job. No coloured ground. |
| Duration | 5s, paused on hover and on focus; a failure toast does not auto-dismiss |
| Undo | at most one action, a `.lnk` at the right |
| A11y | one `role="status"` `aria-live="polite"` container that persists in the DOM; toasts are inserted into it. A failure uses `role="alert"`. It must be reachable by keyboard (`F6` or a skip link) and must not steal focus. |

### 5.19 Chip

Inline-flex, centred, radius 6, `1px solid`, 11/14/600, `tabular-nums`. The three colours come from one ramp slot: `--cN-fill` ground, `--cN-edge` border, `--cN-text` text. Sizes in use: 21×22 in a roster cell, 22×20 in a legend, 30×24 on the specimen sheet, `flex: 1` × 22 in the sheet's cycle preview.

A chip is a shift, identified by its first letter and its colour together. Colour is never the only cue: the letter is in the chip, the legend below the grid spells out every letter with its hours, and the row's Schedule column names the cycle. A11y: the chip's cell carries the full text as an `aria-label` or a visually hidden span — `9 September, Morning, 06:00 to 14:00` — because "M" is not a shift name.

### 5.20 Empty state

Left-aligned, `max-width: 460`, padding `48 0`. Heading 20/26/700 −0.008em; one sentence at 14/20 `--muted` with `padding: 8 0 16`; then the same primary action the title bar offers.

An invitation, not an apology. It says how the thing works, so a first-time user learns the model from it: "Sign-in is by invitation. Invite the people in your HR office who keep the daily time records, and they choose their own password." No illustration, no icon, no centred layout, no "Oops".

Distinguish three cases: **nothing yet** (the invitation above), **nothing matches the filter** (say which filter, offer to clear it, keep the filters on screen), **nothing you may see** (say so plainly and name who to ask; never show an action the user cannot take).

### 5.21 Permission matrix

The invite form's core. A borderless table inside a `.panel-pad` panel, rendering the permission set of `02-access.md` rule 4.

| Part | Spec |
|---|---|
| Preset row | `Start from a preset` at 13/18 `--muted`, spacer, then the segmented control: Admin / HR officer / Viewer / Custom. Editing any box moves the preset to Custom. |
| Head | `<th>` 34 high, padding `0 0 6`, 12/16/600 `--muted`. First column is `What they can reach`; the View and Manage columns are 86 wide and centred. |
| Row | `<td>` 44 high, `border-top: 1px solid var(--rule)`; the first row's top border is `--line`. Label at 14/20. |
| Rows, in order | Agency profile and settings · Users and their permissions · Units, employees, deployments and groups · Shifts, schedules and rosters · Holidays, suspensions, exemptions and overtime · Terminals, enrollments and timelogs · Workdays and daily time records |
| No-view cell | an em dash in `--muted` with `aria-label="No separate view right"` — `agency.manage` and `users.manage` have no paired view right |
| Implied view | checked **and** disabled: the `--edge-soft` box with an `--ink` check, plus a 14px lock icon beside it. `manage` implies `view`, so the view box cannot be unchecked while manage is on. |
| Attest | below the table, above a `1px solid var(--line)` rule at `padding-top: 16`: a single checkbox, `Sign daily time records as the HR officer`, with a hint at 12/16 `--muted`: `Puts their name on CS Form 48 when a ledger is attested. Only the officer who signs needs this.` This is `ledgers.attest`, which is not a view/manage pair. |
| Error | the field's own label row carries `Choose at least one`, and every checkbox border turns `--fault` (`.cb--err`). Nothing moves. |
| A11y | a real `<table>` so each box is announced with its row and column. Every box's accessible name is its permission string (`organization.manage`), which is also what is submitted, so the form and the announcement cannot drift. The label row is the table's `aria-labelledby` source. |

### 5.22 Lane chart

The 24-hour on-duty chart. One lane per shift, drawn on the same 06:00 → 30:00 scale as the day strip and the roster day header.

| Part | Spec |
|---|---|
| Container | `.lanes`, padding `18 20 14`, inside the page's one panel |
| Lane | 32 high. Label gutter 92 at 13/32/500; track `flex: 1`, 32 high, with a 1px `--rule` hairline painted at 50% by a flat `linear-gradient` background (a border would sit at an edge, not the centre); count gutter 52, right-aligned, 13/32/600 `tabular-nums` |
| Bar | absolute, `top: 4`, 24 high, radius 6, `1px solid`, padding `0 7`, `overflow: hidden`, 11/14/600 `tabular-nums`, carrying its own hours (`06:00 – 14:00`). Colours are the shift's ramp slot. |
| Axis | 30 high below the lanes. Baseline 1px `--edge-soft` at `top: 16`; minor hour tick 5px `--tick` at `top: 11`; major tick (06, 12, 18, 24, 30) 10px `--muted` at `top: 6`; label at `top: 18`, 11/14/600 `--muted`, centred by `translateX(-50%)`. A right-aligned note (`06 next day`) is dropped when the axis is too narrow for it. |
| Overlay | one absolutely positioned layer, `pointer-events: none`, inset to the track's own left and right gutters so the lines land on the scale: midnight as a 1px dotted `--edge-soft` (3px on, 3px off, painted with a repeating flat gradient), now as a 1px `--acc` line, and the now label as an 18px `--acc-soft` / `--acc-text` pill at 11/14/600 with a 7px left offset |
| A11y | the chart is a `<figure>` with a `<figcaption>` naming it and the moment ("On duty now, 14:42"). Beside it, or below it, the same numbers as a two-column table, because a bar with its hours inside is legible but a screen reader needs the lane, the hours and the count as text. The now-line and the midnight rule are `aria-hidden`. |

### 5.23 Roster grid

The one memorable element. A month of day columns against employees, grouped by unit and team, with its own scroller, sticky head and two frozen columns.

| Part | Spec |
|---|---|
| Scroller | `.rg`, `position: relative; overflow: auto`; inner `.rg-in` at `width: max-content` |
| Head | 38 high, `position: sticky; top: 0`, z-index 20, ground `--canvas` |
| Frozen head cells | `Employee` at `left: 0` width 200, `Schedule` at `left: 200` width 120 with a `1px solid var(--line)` right border; both z-index 22, `align-items: flex-end`, padding `0 12 7`, 12/16/600 `--muted` |
| Day head cell | 27 wide, a column: weekday letter at 11/13/500 `--muted` over the date at 12/16/600 `tabular-nums`. Weekend → `--weekend` ground. A suspension day → `--attn-soft` ground with `--attn` letters. Today → the date becomes a 20px `--acc` circle with `--acc-ink`. |
| Totals head | right-aligned, `padding 0 10 7`, 12/16/600 `--muted`: Duty, Nights, Off |
| Body | `border-top: 1px solid var(--line)`, `position: relative` |
| Wash | one absolute `pointer-events: none` layer at z-index 0 holding a 27px stripe per weekend and per suspension day, positioned by `left`. Washing the column in one layer keeps the row's own hover on top of it. |
| Now-line | absolute 1px `--acc`, z-index 6, `left = 320 + index × 27` (the frozen columns are inside the body's coordinate space) |
| Group row | 28 high, ground `--row-hover`, `1px solid var(--line)` bottom, z-index 2. Its label is `position: sticky; left: 0` so the team name stays visible while the month scrolls: name 12/16/600 then the cycle at 12/16/400 `--muted` (`Rotation, 21-day cycle, anchored 7 September`). |
| Data row | 34 high, `1px solid var(--rule)` bottom, z-index 1, hover `--row-hover` |
| Frozen row cells | `.emp` at `left: 0` width 200, padding `0 10 0 12`: name 13/17/500 truncated, employee number 11/14/400 `--muted` `tabular-nums`. `.sch` at `left: 200` width 120, 12/16/400 `--muted`, truncated, `1px solid var(--line)` right. Both z-index 4, ground `--canvas`, **and both repaint to `--row-hover` on row hover.** |
| Day cell | 27 wide, centred. A shift is a 21×22 chip in its ramp slot. Off is `--off-fill` plus `repeating-linear-gradient(45deg, var(--off-hatch) 0 1px, transparent 1px 5px)` — data, not decoration. Remote is a 21×22 box, radius 6, `1px dashed var(--edge)`, `--muted` letter. |
| Night band | a night run is **one band per run**, not a chip per day: absolute, `top: 6`, 22 high, z-index 5, radius 6, the slot-8 triple (`--c8-fill` / `--c8-edge` / `--c8-text`), `padding-left: 5`, 11/14/600, carrying `N` and the hours. Geometry, at 27px per day: `left = index × 27 + 12`, `width = nights × 27 + 3` — it opens 12px into its own first day and closes 15px into the day after its last, which is where a 22:00 → 06:00 run actually starts and ends. A run that began before the window renders at `left: -15` with `padding-left: 20` so the label clears the frozen edge. |
| Totals cell | right-aligned, padding `0 10`, 12/16/500 `--muted` `tabular-nums` |
| Legend | `1px solid var(--line)` top, padding `13 20`, gap 18, wrapping. Each item is a 22×20 swatch plus its text at 12/16 `--muted`, spelling out every letter with its hours; the Off swatch is the hatch, Remote is the dashed box, the night band is a 30×14 bar. A day-scoped note (`18 September, work suspended from 12:00`) is pushed to the right in `--attn`. |
| A11y | a real `<table>` if the night band can be expressed per cell; otherwise a `role="grid"` with `role="row"` / `role="gridcell"`, arrow-key navigation, and each cell's full state as its accessible name (`9 September, Morning, 06:00 to 14:00`). A night band spans days, so it is one cell's content with an `aria-label` naming the run and `colspan`-equivalent semantics, and the days it covers are marked as continuations. Sticky and frozen are visual only — the reading order is already row-major. The legend is the text alternative for every colour in the grid and must never be collapsed behind a toggle. |

## 6. Patterns

### 6.1 Forms

One column. 560 wide for a page form (`.form`), 400 for sign-in (`.fc`), the sheet's own 420 minus 40 of padding for a sheet form. Fields stack 24 apart on a page (`.field + .field`), 16 apart in a sheet. A field group that starts a new subject takes 32 (`margin-top: 32`).

**The label row is the validation mechanism.** This is the rule the whole form pattern rests on:

```html
<div class="field">
  <div class="field-hd">
    <label for="f-e">Email</label>
    <span class="err" id="e-e" aria-live="polite">Already taken</span>
  </div>
  <input class="inp" id="f-e" type="email" aria-invalid="true" aria-describedby="e-e">
</div>
```

| # | Rule |
|---|---|
| 1 | `.field-hd` is `display: flex; justify-content: space-between; align-items: baseline; gap: 12; padding-bottom: 6`. |
| 2 | The label sits left at 13/18/500. The error sits right on the **same row** at 13/18/500 `--fault`, `text-align: right`. |
| 3 | The error is **one line**. If the copy does not fit on one line, the copy is wrong, not the layout. |
| 4 | The error span exists whether or not there is an error, so **nothing below it moves** when validation arrives. The row's height is already spent. |
| 5 | The error span carries `aria-live="polite"` so it is announced when it appears without the user losing their place. |
| 6 | The input takes `aria-invalid="true"` and `aria-describedby` pointing at the error's id — and at the form-level banner's id too, space-separated, when both are present. |
| 7 | The input's border and its focus ring both turn `--fault`. Colour is never the only cue: the text says what is wrong. |
| 8 | A hint that is not an error goes **below** the field at 12/16 `--muted` (`.hint`), never on the label row. |

Short copy, one line each. Use these exact strings; add to the table rather than inventing a variant.

| Condition | Copy |
|---|---|
| Empty required field | `Required` |
| Malformed email | `Enter a valid email` |
| Unique violation | `Already taken` |
| Password too short | `At least 8 characters` |
| Confirmation mismatch | `Passwords don't match` |
| Nothing chosen in a group | `Choose at least one` |

**Form-level errors** — an error with no field of its own — render as a banner **above** the fields: one sentence plus the fix, the fix being a link inside the sentence. Sign-in rejected is the canonical case: the banner sits above the fields, the password is cleared, and **neither field's border changes**, because neither field is individually wrong. When an error belongs to one field but needs a sentence to explain it, the banner sits directly under that field (`.banner--field`) and is added to the field's `aria-describedby`; the label row still carries the short form.

**Submit row**: `padding-top: 32`, primary then ghost Cancel, 12 apart. `Forgot password?` goes **under the submit button**, centred, `padding-top: 14` — never on the Password label row, which belongs to that field's error.

### 6.2 Navigation

| Question | Answer |
|---|---|
| What sticks | the title bar at `top: 0` (z 30); a table head at `top: 56` (z 10); the roster head at `top: 0` of its own scroller (z 20); the roster's frozen columns at `left: 0` and `left: 200` |
| When the bar takes its rule | only once the scroller has moved: the scroll container gets `data-stuck="1"` and the bar's transparent `border-bottom` becomes `--line` |
| When the month is the title | on month-scoped pages only — Dashboard, Roster, Workdays, Ledgers. Everywhere else the title is the page name and any date filter is a `.sel` on the right. |
| When breadcrumbs appear | only where depth exists. Invite user shows `Users ›`; Users shows nothing. Never a breadcrumb to the dashboard, never a trailing chevron after the current page. |
| Where the primary action lives | the right end of the title bar, one per page, repeated in the empty state |
| Where filters live | the panel header for a table's own filters; the title bar for page-scoped filters (Unit, Schedule on the roster) |
| Sidebar grouping | Dashboard ungrouped, then Organization, Scheduling, Daily time records, then Users and Settings ungrouped. A count on a nav item means work is held up, and takes the attention variant. |
| Rail | the roster only, so the timetable owns the width |

### 6.3 Data display

| Shape | Use it for | Spec |
|---|---|---|
| Leader-dot row (`.kv`) | a label and one figure, read as a list | 38 min-height, `1px solid var(--rule)` between, label 14/20/400 left, radial-gradient dots filling the gap, value 14/20/600 right, `tabular-nums`. `.bad` / `.att` / `.ok` recolour the value. |
| Navigating figure row (`.kv--nav`) | a figure that is a link to the work behind it | 44 high, radius 8, negative 10px margins so the hover tint bleeds past the column, a `--rule` hairline drawn by `::before` instead of a border, the value at 18/24/600, a chevron in `--edge` that turns `--acc-text` on hover |
| Figure strip (`.figs`) | up to five headline numbers across one section | a column grid, `1px solid var(--line)` between columns and no border on the first, value 24/30/700 `tabular-nums`, label 13/18 `--muted`, delta 12/16/500 with a 9px triangle: `.up` is `--fault`, `.down` is `--pos` (more tardiness is worse, so direction is not sentiment) |
| Person row (`.nite`, `.who2`) | a count that has people in it | 52 min-height, avatar plus name 14/18/500 over unit 12/16 `--muted`, the fact right-aligned at 13/18/500 with its qualifier below in `--attn` |
| Meter row (`.byu`) | a distribution across named buckets | label 148 wide at 13/18, an 8px `--rule` trough with radius 2 filled by `--acc-text` (not `--acc`: on the dark trough `--acc` measures 2.89:1 and the bar is a graphical object under 1.4.11), count 26 wide right at 13/18/600 |
| Split section (`.split`) | two subjects side by side in one section | a grid with a 48 gutter; the right column takes `border-left: 1px solid var(--line)` and `padding-left: 48`. Measured widths: `1fr 420px` for the attention split, `1fr 360px` for the today split. |
| Table | many rows of the same shape, sortable or paged | §5.13, in the page's one panel |
| Lane chart | anything on the 06:00 → 30:00 day scale | §5.22 |
| Roster grid | a month of shifts per employee | §5.23 |

Live values: a live clock is a 6px `--pos` dot before the time, with no animation. `.sub` carries the page's one-line status under the title bar at 13/18 `--muted`, `tabular-nums`.

### 6.4 Feedback

| Event | Where it goes | Why |
|---|---|---|
| One field is wrong | inline, right-aligned on that field's label row | the eye is already on the field, and the row cannot move |
| The submission is wrong but no single field is | a `--fault-soft` banner above the fields, one sentence plus the fix, `role="alert"` | the user must not hunt for a red border that does not exist |
| A field needs an explanation as well as a verdict | the short verdict on the label row, the sentence in a `.banner--field` under the field | the label row stays one line |
| It worked | a toast named after the button, in the past tense, 5s | the page has already changed; the toast only confirms which button did it |
| It will destroy something | a dialog: the title asks, the body names the consequence in one sentence, the confirm button repeats the verb and the object | a destructive button alone is not consent |
| There is nothing here | the empty state, as an invitation that teaches the model | a first-time user learns what the screen is for |
| A background job is running | `aria-busy` on the region, skeleton rows at the real row height, the control disabled with its label intact | never a full-page spinner; the shell is already correct |
| A condition persists | a banner in the content, no dismiss | a toast that must be re-read is the wrong container |

## 7. Colour in data

### 7.1 The eight-slot chip ramp

Eight flat oklch hues. Every hue keeps ≥35° clear of the violet accent (oklch h293) so a chip never competes with a button, and every text-bearing chip keeps ≥20° clear of fault red (h27.5) so a chip never reads as an error. Construction, held constant across the ramp: light `fill L.94 C.064 · edge L.80 C.125 · text L.425 C.14`; dark `fill L.28 C.09 · edge L.44 C.12 · text L.80 C.12`.

`On fill` is the chip's text against its own fill — the ratio that actually matters, since chip text always sits on chip fill. `On canvas` is the same text against the page ground, for a chip letter used without a fill.

| Slot | Hue | Light fill | Light edge | Light text | Light on fill / on canvas | Dark fill | Dark edge | Dark text | Dark on fill / on canvas | Seeded shift |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | rose 355° | `#FFE2EC` | `#FD9BC2` | `#852252` | 7.39 / 8.94 | `#48102B` | `#833156` | `#FB9DC2` | 7.73 / 10.09 | Ramadan |
| 2 | ochre 55° | `#FFE6D5` | `#FCA76C` | `#793B00` | 7.19 / 8.61 | `#421E00` | `#7F3F00` | `#F9A870` | 7.66 / 10.24 | Morning |
| 3 | olive 85° | `#FFE9BB` | `#E3B756` | `#644A00` | 6.99 / 8.33 | `#362600` | `#694E00` | `#E1B75C` | 7.76 / 10.49 | Day12 |
| 4 | lime 118° | `#E7F2C2` | `#B7C967` | `#4A5500` | 6.90 / 8.11 | `#262D00` | `#4E5900` | `#B7C86B` | 7.88 / 10.83 | Long |
| 5 | green 150° | `#CEF8D5` | `#7FD492` | `#005F29` | 6.75 / 7.87 | `#003312` | `#03642B` | `#83D494` | 7.97 / 11.13 | Afternoon |
| 6 | teal 186° | `#BBFAF1` | `#41D7CA` | `#005B54` | 6.88 / 8.00 | `#00312C` | `#006059` | `#4BD7C9` | 8.04 / 11.18 | Standard |
| 7 | sky 219° | `#CCF3FF` | `#48D0F4` | `#00586B` | 6.84 / 8.05 | `#002E3A` | `#005C70` | `#50D0F2` | 8.02 / 10.98 | Flexi |
| 8 | blue 252° | `#DDEDFF` | `#8CC2FF` | `#004F91` | 6.98 / 8.31 | `#002950` | `#125492` | `#8CC2FF` | 7.87 / 10.63 | Night, Night12 |

Under the blue accent, **slot 8 alone rotates** from 252° to orchid 320°, because h252 collides with the blue accent's h263 and slot 8 is the slot night shifts use. Slots 1–7 are untouched.

| Slot | Hue | Light fill | Light edge | Light text | On fill / on canvas | Dark fill | Dark edge | Dark text | On fill / on canvas |
|---|---|---|---|---|---|---|---|---|---|
| 8 (blue accent) | orchid 320° | `#FAE2FF` | `#E1A3F0` | `#6E2E7D` | 7.40 / 8.94 | `#3B1644` | `#6F397B` | `#E0A4EE` | 7.74 / 10.10 |

Chip edges are decoration on a filled chip and are not held to 3:1; they measure 1.78–1.97 on the light canvas and 2.39–2.70 on the dark. What carries the meaning is the letter, at ≥6.75:1 on its own fill in every slot and every mode, plus the legend.

### 7.2 The assignment rule for `shifts.color`

`shifts.color smallint NOT NULL`, range 1–8, checked in Postgres (`04-scheduling.md` rule 8, `07-constraints.md`). Stored on the row, never derived from the name or the id — hashing an identifier looks stable but collides inside one agency and cannot be corrected.

| # | Rule |
|---|---|
| 1 | A new shift takes the **lowest index its agency is not already using**, and wraps at 8. |
| 2 | A copy from the platform agency carries the **origin row's index**, so an agency's Morning is the same ochre as the default Morning. |
| 3 | HR may change a shift's index. The UI offers the eight slots as swatches, marking which are already in use. |
| 4 | `Off` and `Remote` **never take an index**. They are drawn from empty `slots` and the `remote` flag: Off is the hatch, Remote is the dashed box. Their `color` value is never read. |
| 5 | More than eight shifts in one agency means a repeated colour. That is accepted: the letter and the legend disambiguate, and the roster's Schedule column names the cycle. |
| 6 | The same index drives the roster chip, the lane-chart bar, the sheet's cycle preview and the legend swatch, so one shift is one colour everywhere. |

`Duty24` is not in the seeded mapping above because the mockups do not draw it; under rule 1 it takes the lowest free index at seed time.

### 7.3 Avatar tints

`.a1`…`.a8` draw `--cN-fill` and `--cN-text` from the same ramp — no second palette. The slot comes from the person's **name**, assigned deterministically so the same person is the same colour on every screen and across sessions. A shift's slot comes from `shifts.color`; a person's comes from their name. The two never need to agree.

## 8. Dark mode and accent

Both are **attributes on `<html>`**, not classes:

```html
<html lang="en" data-mode="light" data-accent="violet">
```

| # | Rule |
|---|---|
| 1 | `data-mode` is `light` or `dark`. **It is not shadcn's `.dark` class.** The Tailwind v4 variant must be redefined: `@custom-variant dark (&:where([data-mode="dark"], [data-mode="dark"] *));` — the `*` half lets a subtree be forced to one mode if that is ever needed. |
| 2 | `data-accent` is `violet` or `blue`. Bare `:root` is violet + light, so a page with no attributes and no JavaScript renders correctly. |
| 3 | The attribute must be **stamped synchronously in `<head>` before first paint**, from the Blade layout, or the page flashes light before React hydrates. A React effect is too late. The client store re-stamps it on change. |
| 4 | The user's choice is three-way — Light, Dark, System — rendered as the full-width segmented control in the user menu. `System` stores no mode and follows `prefers-color-scheme` through a `matchMedia` listener, which then stamps `light` or `dark`; the attribute is always one of the two concrete values. |
| 5 | `color-scheme` is declared inside each mode block so native form controls, scrollbars and the caret follow. |
| 6 | **v1 ships one accent: violet.** Blue exists in the token file, is complete and is switchable by stamping the attribute, and is **not exposed to users**. It is there so a second brand can be turned on without re-deriving a palette. Its slot-8 rotation (§7.1) is part of that switch. |
| 7 | Nothing that varies by mode or accent may live in `@theme`. `@theme inline` emits `var(--token)` rather than a copy of the value, so a utility follows whichever block is active; the four-way matrix itself lives in plain CSS. |

## 9. Implementer mapping

Stack: Laravel 13 + Inertia React 3, Tailwind v4, shadcn `new-york` with `baseColor: neutral` and `cssVariables: true`, Radix primitives, lucide icons, `sonner` for toasts, `@tanstack/react-table` for tables. The four token sets live in `resources/css/app.css`; `mockups/tokens.css` stays the design of record and the hex values are not re-derived in the app.

### 9.1 shadcn CSS variables → khronoz tokens

Two renames are forced by shadcn's fixed names, and both are collisions worth reading twice:

| Design token (`tokens.css`) | shadcn variable | Note |
|---|---|---|
| `--muted` (muted **text**) | `--muted-foreground` | shadcn's `--muted` is a **surface** |
| `--side` (the sidebar **grey**) | `--muted` and `--sidebar` | so `--muted` is `#F5F5F5`, not the text colour |
| `--fault` / `--attn` / `--pos` | `--destructive` / `--attention` / `--positive` | the last two are additions; shadcn has no name for them |

| shadcn variable | Light | Dark | Khronoz token |
|---|---|---|---|
| `--background` | `#FFFFFF` | `#0A0A0A` | `--canvas` |
| `--foreground` | `#171717` | `#EDEDED` | `--ink` |
| `--card` / `--card-foreground` | `#FFFFFF` / `#171717` | `#171717` / `#EDEDED` | `--sheet` / `--ink` |
| `--popover` / `--popover-foreground` | `#FFFFFF` / `#171717` | `#171717` / `#EDEDED` | `--sheet` / `--ink` |
| `--muted` | `#F5F5F5` | `#171717` | `--side` |
| `--muted-foreground` | `#6B6B6B` | `#A3A3A3` | `--muted` |
| `--border` | `#E5E5E5` | `#262626` | `--line` |
| `--input` | `#8A8A8A` | `#737373` | `--edge` — **must be darker than `--border`** |
| `--ring` | `#6D28D9` | `#7C3AED` | `--acc` |
| `--primary` / `--primary-foreground` | `#6D28D9` / `#FFFFFF` | `#7C3AED` / `#FFFFFF` | `--acc` / `--acc-ink` |
| `--primary-hover` | `#5B21B6` | `#6D28D9` | `--acc-hover` (an addition; shadcn has no hover token) |
| `--secondary` / `--secondary-foreground` | `#F5F5F5` / `#171717` | `#1F1F1F` / `#EDEDED` | `--row-hover` / `--ink` |
| `--accent` / `--accent-foreground` | `#F5F5F5` / `#171717` | `#1F1F1F` / `#EDEDED` | `--row-hover` / `--ink` — **shadcn's `--accent` is the neutral hover surface, not the brand accent.** The brand accent is `--primary`, and khronoz's accent-tinted surfaces are the additions `--acc-soft`, `--acc-tint`, `--acc-text`. |
| `--destructive` / `--destructive-foreground` | `#B91C1C` / `#FFFFFF` | `#F87171` / `#171717` | `--fault` / contrast ink |
| `--sidebar` | `#F5F5F5` | `#171717` | `--side` |
| `--sidebar-foreground` | `#171717` | `#EDEDED` | `--ink` |
| `--sidebar-primary` / `-foreground` | `#6D28D9` / `#FFFFFF` | `#7C3AED` / `#FFFFFF` | `--acc` / `--acc-ink` |
| `--sidebar-accent` / `-foreground` | `#EAEAEA` / `#171717` | `#262626` / `#EDEDED` | `--side-hover` / `--ink` — the sidebar's **hover**; the *active* item sets `--acc-soft` and `--acc-text` itself |
| `--sidebar-border` | `#E5E5E5` | `#262626` | `--line` |
| `--sidebar-ring` | `#6D28D9` | `#7C3AED` | `--acc` |

Additions with no shadcn equivalent, exposed through `@theme inline` so they become utilities: `--rule`, `--edge-soft`, `--row-hover`, `--side-hover`, `--weekend`, `--off-fill`, `--off-hatch`, `--tick`, `--dot`, `--scrim`, `--lift`, `--acc-soft`, `--acc-tint`, `--acc-text`, `--attention`, `--attention-soft`, `--positive`, `--positive-soft`, and the twenty-four ramp variables `--c1-fill` … `--c8-text`. Chart tokens read the ramp so a lane and its legend agree: `--chart-1…5` = `--c2-edge`, `--c5-edge`, `--c8-edge`, `--c1-edge`, `--c6-edge`.

### 9.2 Radius

`--radius: 0.5rem` (8px), so shadcn's four derived steps land exactly on the design's four shapes:

| Utility | Derivation | px | Use |
|---|---|---|---|
| `rounded-sm` | `--radius - 4px` | 4 | unused; no control is this tight |
| `rounded-md` | `--radius - 2px` | 6 | chips, badges, menu items, segmented inner buttons |
| `rounded-lg` | `--radius` | 8 | **every control**: button, input, select, nav item, banner |
| `rounded-xl` | `--radius + 4px` | 12 | panels, cards, sheets, dialogs |
| `rounded-full` | — | 999 | pills, avatars, the switch track |

shadcn's own components default to `rounded-md`, which is 6 under this scale. Every control must be moved to `rounded-lg`, every container to `rounded-xl`.

### 9.3 Font

```html
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800">
```

```css
@theme inline {
  --font-sans: 'Plus Jakarta Sans', 'Segoe UI', ui-sans-serif, system-ui, sans-serif;
}
```

`body` gets `font-size: 14px; line-height: 20px; font-variant-numeric: tabular-nums`. `fontaine` is already a dependency; use it to generate the metric-matched fallback so the swap does not shift layout.

### 9.4 Traps

| # | Trap | What to do |
|---|---|---|
| 1 | **`font:` shorthand.** A CSS-wide keyword used for one *component* of the shorthand makes the whole declaration invalid, and it is silently dropped. Measured: `font: 40px/1 inherit` on a 100px parent leaves the element at 100px — the declaration never applied. Separately, **any** valid `font:` shorthand resets `font-variant-numeric` to `normal`: measured, `font: 40px "Plus Jakarta Sans"` under a `tabular-nums` body yields `font-variant-numeric: normal` and proportional digits. | Longhand font properties only, everywhere. The control reset is `font-family: inherit; font-size: inherit; line-height: inherit; font-weight: inherit; letter-spacing: inherit; color: inherit` — six declarations, not one. |
| 2 | **`--input` tied to `--border`.** shadcn ships them equal, which puts every control border at 1.26:1 and fails WCAG 1.4.11. | `--input` is `--edge` (3.45:1 light, 4.18:1 dark) and is a different, darker value from `--border`. Never fold them. |
| 3 | **shadcn's `Card` ships a border *and* a shadow**, and gets used as a generic wrapper. | Drop the shadow. Use `Card` only where grouping needs a container — a table, a chart, a matrix. A stat, a field or a list of rows gets a section rule instead. |
| 4 | **A sortable `<th>` with only `aria-sort`** is announced but cannot be operated by keyboard (2.1.1). | Put a real `<button>` inside the `<th>` and hang the click on it. `aria-sort` stays on the `<th>`. |
| 5 | **Overlays parented to the scroll container.** A popover, menu, sheet or dialog inside `overflow: auto` scrolls away from its trigger and is clipped. | Portal every overlay to the positioned app root, a sibling of the shell. Both the row menu and the sheet do this in the mockups. Radix portals by default — do not disable it to "keep the DOM tidy". |
| 6 | **Sticky table head with the background on `thead`.** A `thead` background does not paint under a sticky cell, so rows show through. | Put `position: sticky`, `top`, `z-index` and `background` on the `<th>`. |
| 7 | **Frozen columns without an opaque background.** The default is transparent, so the body scrolls under them; and the row's `:hover` tint slides under a frozen cell that only sets a rest background. | Frozen cells set `background: var(--canvas)` at rest **and** override to `var(--row-hover)` under `.rg-row:hover`. |
| 8 | **shadcn's `--accent` read as the brand accent.** It is the neutral hover surface. | Brand fill is `--primary`; brand tints are `--acc-soft`, `--acc-tint`, `--acc-text`. |
| 9 | **`.dark` class from any shadcn snippet or block.** | The variant is redefined to `[data-mode="dark"]`; a pasted `.dark` selector silently never matches. |
| 10 | **`sonner`'s default coloured toasts.** | Neutral surface, `--lift`, the icon carries the sentiment. Configure once, not per call. |
| 11 | **Radix `Dialog` used for the sheet.** | Correct primitive, wrong geometry: the sheet is a full-height 420 panel pinned right with a left border, not a centred box. Both are `role="dialog" aria-modal="true"`. |
| 12 | **Tailwind's `divide-*` and `border-*` defaults** pick `--border` for row dividers. | Row dividers are `--rule`, which is lighter than `--line`. A table's rows and its head rule are two different greys. |

## 10. What is not built yet

The mockups show the finished product. Milestone 1 covers agencies, users, permissions and the shell; two of the eight artboards depend on models that arrive later.

| Screen or component | Needs | Milestone |
|---|---|---|
| Sign-in, users, invite user, the shell (sidebar, title bar, user menu, appearance) | `Agency`, `User` — all present | M1 |
| Foundations sheet | nothing; it is the specimen and is already true | M1 |
| Dashboard — the shell, the title bar, the section rules, the empty and attention states | `Agency`, `User` | M1 |
| Dashboard — the figure strip, the attention counts, the ledger split | `Workday`, `Ledger` | after attendance |
| Lane chart (on duty now) | `Shift` with `slots`, `Schedule`, `Turn`, `Roster`, `Workday` | after scheduling and attendance |
| Roster grid — day columns, chips, night bands, group rows, totals | `Shift` (incl. `color`), `Schedule`, `Turn`, `Roster`, `Workday` | after scheduling |
| Roster grid — weekend and suspension wash, the legend note | `Holiday`, `Suspension` | after calendar |
| Assign-schedule sheet and the cycle preview | `Schedule`, `Turn`, `Roster` | after scheduling |
| Day strip in the sidebar (headcount on duty) | `Workday` | after attendance |
| Workdays and Ledgers screens, CS Form 48 | `Workday`, `Punch`, `Ledger`, `Attestation` | after attendance |
| Terminals and timelog resolution screens | `Terminal`, `Enrollment`, `Timelog` | after terminals |

The nav item for a screen that does not exist yet is not rendered. The sidebar in M1 shows Dashboard, Users and Settings; the Organization, Scheduling and Daily time records groups appear as their models land.

## 11. Measured, and where the sources disagree

Everything above is read out of the mockups. Six things were checked independently, and seven places needed a ruling.

| # | Check | Result |
|---|---|---|
| 1 | Every ratio in §2 recomputed from the hex values, WCAG 2.x relative luminance | reproduces `tokens.css` exactly, to two decimals, for all neutrals, both accents and the light ramp |
| 2 | Neutrals are achromatic | R = G = B on all 21 neutral tokens in both modes |
| 3 | `tnum` in Plus Jakarta Sans as served by Bunny | present; see §3.2 for the three-way verification |
| 4 | `font:` shorthand behaviour | both failure modes reproduced in a browser; see §9.4 trap 1 |
| 5 | The meter bar's colour choice | confirmed: `--acc-text` on the `--rule` trough measures 6.23 / 6.06 violet and 5.88 / 9.14 blue, and the rejected `--acc` on the dark trough measures 2.89, below the 3:1 a graphical object needs |
| 6 | The derived ratios published in `ui.css` and the foundations sheet for `--acc-tint` and the locked checkbox | re-derived; see the disagreement table below |

| Disagreement | Sources | Ruling |
|---|---|---|
| `--tick` | `tokens.css` says `#8E8E8E` / `#666666` (3.28 / 3.01 light, 3.45 / 3.12 dark); the foundations sheet's neutral table says `#ADADAD` / `#525252` at 2.24 / 2.53 | **`tokens.css`.** It is the stylesheet that renders every artboard, and its values are the only ones that clear 3:1 for an axis tick, which is a graphical object under 1.4.11. The foundations table is a stale caption. |
| Tabular figures | `ui.css`'s `body` comment and the foundations sheet's fallback-stack note both state that the face ships no tabular-figure feature and that `font-variant-numeric` is therefore a no-op | **Both are wrong**, and were measured wrong in the browser. §3.2 records the correct measurement. `font-variant-numeric: tabular-nums` on `body` is load-bearing; structural right-alignment stays as a fallback safeguard, not as the mechanism. |
| Dark chip-ramp "on ground" ratios | `tokens.css` and the foundations sheet give a second figure per dark slot (e.g. slot 1 at 9.56) that matches neither `--canvas` `#0A0A0A` nor `--side` `#171717` | **Recomputed against the shipped `--canvas`**, which gives higher (better) numbers, and those are the ones in §7.1. The published figures appear to have been measured against an older, lighter dark canvas; they are conservative, not unsafe. The `on fill` figures, which are the ones that matter, match exactly. |
| Wordmark tracking | `02-sign-in.html` draws it at −0.022em; the foundations sheet labels the same role −0.016em | **`02-sign-in.html`**, the screen that actually renders the wordmark. |
| The `--acc-tint` ratio caption | the foundations sheet gives one figure pair per accent, and the pair means different things in each: violet's 6.48 / 6.37 is `--acc-text` on the tint, blue's 6.16 / 6.86 is `--acc-text` light but `--muted` dark (6.83) | **Re-measured all three pairs** and published them in §2.3–2.4, labelled. The tint's real job is a selected table row, so `--ink` on it is the figure that governs: 16.35 / 14.80 violet, 16.47 / 14.72 blue. |
| The locked checkbox ratio | `ui.css` states 11.94:1 light and 9.35:1 dark for `--ink` on `--edge-soft` | **Re-measured: 12.09 / 10.79.** The published figures are conservative; neither reading changes the decision, and the lock icon, not the ratio, is what carries the meaning. |
| Toast and centred dialog | neither is drawn in any artboard | Specified in §5.16 and §5.18 from the popover and sheet primitives, reusing their exact tokens. No new value is introduced. Flagged here so a reviewer knows these two were derived, not copied. |
