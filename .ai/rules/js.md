---
paths:
  - 'resources/js/**'
---

# Js

## Page files, Wayfinder forms, shadcn primitives, tokens
Pages live at pages/<resource>/<action>.tsx and post with Inertia `<Form>` + a Wayfinder action. UI primitives come from components/ui only — never a fresh shadcn install, never a one-off control in a page.

Design source of record: `docs/design/08-interface.md` plus `docs/design/mockups/` (tokens.css, ui.css, the numbered artboards). The mockups win when they and the doc disagree.

Tokens live in resources/css/app.css: the four [data-accent="violet"|"blue"] x [data-mode="light"|"dark"] sets, with shadcn's variables mapped onto them (`--input` is the control edge and is deliberately darker than `--border`). Dark mode is `data-mode="dark"` on `<html>`, not the `.dark` class — use the `dark:` variant or the tokens, and read/write the mode through hooks/use-appearance.ts. `--radius` is 8px, which puts rounded-md at 6 (chips), rounded-lg at 8 (controls) and rounded-xl at 12 (panels). Neutrals are pure achromatic; only the accent, the eight-slot chip ramp (`c1..c8` fill/edge/text) and the fault/attention/positive families carry hue. No shadow outside popover, menu, dialog and sheet.

Font is Plus Jakarta Sans (400-800); titles are 700. Every time, date, count and minute figure needs tabular numerals — the face's default figures are proportional and ragged (5.19-10.25px at 14px), so `font-variant-numeric: tabular-nums` is load-bearing. It is on `body` and `time`/`.tnum`; add `tabular-nums` to any other figure.

Validation uses components/field.tsx: the label row carries the one-line fault message in an aria-live slot at a fixed height, so an error never shifts the page. Write the message server side (lang/en/validation.php, or a Form Request's messages()/attributes()) — short forms only. A failure that belongs to no field goes under a non-field key (`form`) and renders as an `<Alert>` banner above the fields.

Controls use `transition-[color,background-color,border-color]`, not `transition-colors`: Tailwind's list includes `outline-color`, which makes the focus ring fade in from currentColor.

## Label-row validation: the empty error still needs a line box, and a sentence needs its own key
`components/field.tsx`'s error paragraph renders `{error || '​'}`. The zero-width space is load-bearing: an empty block has no line box, so the row's `items-baseline` falls back to its bottom margin edge and the label row is ~4px taller *without* a message than with one — the exact shift the layout exists to prevent. Keep it in any hand-rolled label row too (permission-matrix.tsx has one).

A field whose verdict needs a sentence as well (§6.4) gets two server-side keys: the field's own key carries the one-line verdict (`Already taken`), and a second, non-field key carries the sentence a banner under the field renders (`email_conflict` in StoreUserRequest). `form` stays reserved for a failure that belongs to no field and renders above the fields. The banner is an `<Alert role={undefined}>` referenced by the input's `aria-describedby` — never a second `role="alert"` competing with the label row that already announced the verdict.
