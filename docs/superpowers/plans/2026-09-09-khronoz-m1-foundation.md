# khronoz Implementation Plan — roadmap and Milestone 1 (Foundation)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Work on `master` directly: no branches, no worktrees. Each task ends with a commit; nothing is pushed unless the user asks.

**Goal:** Turn the approved design in `docs/design/` into working software, milestone by milestone, starting with the multi-tenant foundation every later concern depends on.

**Architecture:** One Postgres database, `agency_id` on every tenant table with paired foreign keys, integrity in Postgres (constraints, triggers, privileges). Laravel 13 serves Inertia React pages through thin controllers that call action classes; a token API reuses the same actions later. A `Tenant` service holds the current agency per request; an Eloquent global scope keys every tenant model to it. Platform users (users of the one `platform = true` agency) enter an agency and work inside it, so scoping has one code path.

**Tech Stack:** PHP 8.5.10, Laravel 13.31, Postgres 18 (Docker `khronoz-pgsql` on 43432), Octane/Swoole on 43080, Horizon on Valkey, Inertia 3.3 + React 19 + TypeScript 7 + Vite 8, Tailwind 4.3, shadcn/ui (new-york, CSS variables), Laravel Wayfinder, PHPUnit 12 against the `testing` database, Pint, Laravel Boost MCP.

**Spec:** `docs/design/README.md` and `docs/design/00-…07-*.md` (design files are the spec; no separate spec file is written). Law inputs: `docs/reference/csc-rules.md`. Predecessor audit: `docs/reference/clockwork-audit.md`.

## Global Constraints

- Postgres is the source of truth for integrity: every constraint and trigger in `docs/design/07-constraints.md` ships with one PHPUnit test that performs the violation and asserts the database refused it (07 says "Pest"; this project uses PHPUnit).
- Every table carries `agency_id NOT NULL` and `UNIQUE (id, agency_id)`; every child FK is paired `(x_id, agency_id) → parent (id, agency_id)`; FKs are `ON DELETE RESTRICT ON UPDATE RESTRICT` unless 07 says otherwise.
- ULID primary keys everywhere (`char(26)`); single-word model names, plural snake_case tables; enums are `varchar` + `CHECK IN (...)` mirrored by a PHP backed enum.
- The application connects as Postgres role `khronoz_app` (connection `pgsql`); migrations run on connection `owner`. Command: `php artisan migrate --database=owner` (wrapped as `composer migrate`).
- Timezone `Asia/Manila`; device times are naive local time and stored as given; minutes everywhere, days only at report time via the CSC lookup table.
- Global rows (national holidays, default shifts and schedules, superusers) belong to the platform agency row, never to `agency_id` null.
- A **timelog** is what the terminal recorded; a **punch** is one matched slot side of a workday. Never the other way round.
- Front end: Inertia pages under `resources/js/pages/<resource>/<action>.tsx` (kebab-case), shadcn primitives in `resources/js/components/ui/`, Wayfinder-generated route functions for every href and form action, one design token system in `resources/css/app.css`.
- Boost rules: run `search-docs` before using a Laravel/Inertia API; run `vendor/bin/pint --dirty --format agent` after PHP edits; `php artisan make:*` with `--no-interaction` for new files; no new dependencies beyond the approved list below.
- Commit per completed task on `master`; never push unless asked.

---

## Context

The design was approved decision by decision on 2026-09-08 (README decisions 1–15) and committed (`fdb24a1`). A plain Laravel 13.31 install (`c2923f4`) and the first-party package layer plus Inertia React front end (`4d067d4`) followed. The repo today has: Passport/Sanctum/Socialite/Scout/Horizon/Octane/Telescope configured, Passport models renamed to one word (`Client`, `Token`, `Refresh`, `Code`, `Device`), a ULID `users` table, an Inertia welcome page with SSR opt-in, no auth scaffolding, no shadcn, no domain tables, two example tests. Docker holds only dependencies; the app runs on the host.

The user asked to proceed to planning using Laravel Boost, shadcn for components, and UX/frontend design skills. Answers given during planning (2026-09-09):

| Question | Answer | Effect |
|---|---|---|
| `Device` name collision with Passport's device-code model | Keep Passport `Device`; biometric device becomes **`Terminal`** | Design 00/03/04/06/07/README updated in Task 1: `terminals`, `terminal_id`, natural key `(terminal_id, uid, time, state, mode)` |
| Dependencies | All recommended, provided shadcn components come from the official registry | `npx shadcn@latest add …` uses the official registry; no third-party registry is needed. `@tanstack/react-table` is the only extra runtime lib (shadcn's data-table recipe) |
| Roles | **Permissions instead of a role enum** | `users.permissions jsonb` array of strings checked by `permissions_valid()`, PHP `Permission` enum, `Preset` bundles for the invite form; design 02/07 updated in Task 1 |
| Commits | Commit per task, on `master`, no branching | Every task ends with a commit step; no worktrees |

Assumptions made without asking (say if not):

1. Auth is hand-rolled on framework primitives (`Auth::attempt`, the password broker, `MustVerifyEmail`, signed invite URLs). Fortify's stable line supports Laravel 12 only; when a Laravel 13 release lands it can replace the controllers without touching pages.
2. Employee logins are invite-only (decision 15); there is no self-registration route.
3. Superusers default to the platform agency as their tenant after login (that is where defaults and agencies are managed) and "enter" an agency to work inside it. The Defaults screen of decision 14 is simply the platform agency as tenant.
4. The tenant scope fails closed: reading a tenant model with no tenant set throws `TenantNotResolved` in HTTP and in tests, and is unscoped only for real console runs (seeders, maintenance commands that iterate agencies and set the tenant themselves). `User` and `Agency` carry no tenant scope because authentication resolves users before any tenant exists; cross-tenant `{user}` bindings still 404 through `resolveRouteBindingQuery()`. `SetTenant` is placed in the middleware priority list ahead of `SubstituteBindings`, otherwise route bindings would resolve before the tenant is known (verified against the installed framework).
5. CS Form 48 in v1 prints through the browser (print stylesheet, "Save as PDF"); a server-side PDF library is a later dependency decision.
6. Terminal `pull` protocol (vendor TCP SDK) is last in Milestone 5 and needs real hardware to verify; `file` import and `push` (ADMS/iClock) come first.

## Delivery approach

- This document = roadmap for all milestones + the fully detailed plan for **Milestone 1**.
- Each later milestone gets its own bite-sized plan at `docs/superpowers/plans/2026-MM-DD-khronoz-mN-<name>.md`, written with the writing-plans skill right before it starts, so it reflects what earlier milestones actually produced. This plan is saved to `docs/superpowers/plans/2026-09-09-khronoz-m1-foundation.md` as the first step of execution.
- Execution: subagent-driven development, one fresh subagent per task, two-stage review between tasks, on `master`.

| # | Milestone | Design files | Produces |
|---|---|---|---|
| 1 | Foundation | 00, 01 (agencies), 02, 07 | DB roles, agencies + platform row, users + permissions, auth, tenancy, app shell, platform area, users management |
| 2 | Organization | 01, 07 | units tree, employees, deployments, groups, members; employee directory UI |
| 3 | Scheduling | 04, 07 | shifts (slot editor), schedules + turns (cycle builder), rosters, platform defaults + copy/refresh, roster grid, shift resolver |
| 4 | Calendar | 05, 07 | holidays (national + local), suspensions, exemptions, overtimes; calendar UI |
| 5 | Terminals and timelogs | 03, 07 | terminals, enrollments, syncs, timelogs with resolve trigger and privileges; file import, push endpoint, manual entry, void; pull driver last |
| 6 | Attendance engine | 06, 07 | workdays, punches, ledgers; compute pipeline; recompute jobs; lock/unlock |
| 7 | DTR and attestation | 06 | employee month view, CS Form 48 renderer, ledger views (period/work), occurrences, attestations |
| 8 | Settings and audits | 00 tier 3, decision 15 | typed settings, `settings_valid()`, audits table + trigger + per-request user GUC, audit screen |
| 9 | Hardening and ops | all | onboarding-test seeders A–E, Horizon/scheduler config, indexes, accessibility pass, platform user management, release checklist |

Phase 2 (not planned here): biometric `Template`, token API for mobile over the same actions, filing/approval workflows, fine-grained permissions beyond the v1 set.

## Conventions every task follows

### Backend layout

```
app/
  Actions/            verbs, one public handle(): InviteUser, CreateAgency, EnterAgency
  Enums/              Permission, Preset (later: TerminalKind, Protocol, HolidayType, ExemptionType, WorkdayStatus, PunchKind, Period, Work, Trigger, Source, Mode)
  Http/Controllers/   resource controllers; Auth/*, Platform/*
  Http/Middleware/    SetTenant, EnsurePlatform, HandleInertiaRequests
  Http/Requests/      Form Requests for every write endpoint
  Http/Resources/     JsonResource per model; the prop shape for Inertia and, later, the API
  Models/             single-word models; Concerns/BelongsToAgency; Scopes/AgencyScope, NotPlatformScope
  Notifications/      InviteNotification
  Policies/           one per model, auto-discovered
  Tenancy/            Tenant (scoped service)
  Support/            Dashboard (exists)
database/migrations/  prepare_database, then one migration per table; DDL from 07 verbatim via DB::statement/unprepared
tests/Feature/        mirrors app/ (tests/Feature/Models/AgencyTest.php tests App\Models\Agency); tests/Feature/Database/* for constraint tests
```

- Controllers coordinate HTTP only: Form Request → action or model → redirect with `flash`. Inertia responses return `Inertia::render('users/index', [...])` with `JsonResource::collection(...)->resolve()` shapes.
- Tests: `test_` prefix, `LazilyRefreshDatabase`, real Postgres, factories with named states, `#[DataProvider]` for permission matrices, `Notification::fake(InviteNotification::class)` with class names, `$this->freezeTime()` where dates matter. Cross-tenant requests assert **404**.
- Every DB constraint test: perform the violation through the `pgsql` (app) connection and assert `QueryException` with the SQLSTATE (`23505` unique, `23514` check, `23P01` exclusion, `23503` FK, `42501` insufficient privilege, `P0001` raise_exception) using `$this->expectException(QueryException::class)` plus `$this->assertSame('23514', $e->getCode())` inside a try/catch helper `assertDatabaseRefuses(string $sqlstate, callable $fn)` added to `tests/TestCase.php`.

### Front-end layout

```
resources/js/
  app.tsx, ssr.tsx               (exist)
  lib/utils.ts                   cn() from shadcn init
  components/ui/*                shadcn primitives only, never edited by hand except tokens
  components/                    app-sidebar, agency-switcher, nav-user, page-header, input-error, permission-picker, empty-state
  layouts/app-layout.tsx         sidebar + breadcrumbs + content
  layouts/auth-layout.tsx        centered single column
  pages/<resource>/<action>.tsx  index, create, edit, show
  hooks/use-can.ts               permission check from shared props
  types/index.d.ts               SharedProps, AuthUser, Agency, Permission
  actions/, routes/, wayfinder/  generated by Wayfinder, gitignored
```

- Forms use Inertia 3 `<Form action={store()} method="post">` with Wayfinder actions and render-prop `errors`/`processing`; inputs are shadcn `Input`/`Label`/`Select`/`Checkbox`; errors render through `InputError`.
- Every href is a Wayfinder function (`index()`, `edit(user)`); no hand-typed URLs.
- `npm run types` (tsc) and `npm run lint` must pass before a task's commit; `npm run build` before a milestone closes.

### Design brief (frontend-design skill, applied in Task 2 and every UI task)

Subject: a scheduling and Daily Time Record system for Philippine government HR offices. Audience: HR officers rostering staff, unit heads verifying DTRs, employees reading their own month. Primary job: build and read schedules and the monthly record fast, without ambiguity.

| Token | Light | Dark | Role |
|---|---|---|---|
| `--canvas` | `#F4F6F8` | `#0E1420` | page background (cool grey, not cream) |
| `--surface` | `#FFFFFF` | `#151D2B` | tables, forms, dialogs |
| `--ink` | `#14213D` | `#E6EAF0` | text |
| `--muted` | `#5B6472` | `#9AA4B2` | secondary text |
| `--line` | `#D9DEE5` | `#2A3446` | hairline borders, the only separator |
| `--primary` | `#0B6B5D` | `#3EA98F` | actions, selected state; green after the DTR form's ink and bundy-clock enamel |
| `--primary-soft` | `#E3F2EE` | `#123A33` | selected rows, scheduled chips |
| `--danger` | `#B42318` | `#F97066` | tardy, undertime, absent, destructive |
| `--warning` | `#B54708` | `#FDB022` | pending outs, unresolved timelogs |

- Type: **Public Sans** only (Bunny Fonts, weights 400/500/600/700, italics available); base 14px for dense tables, scale 12/13/14/16/20/24/30, body line-height 1.45, headlines tight. All times and minutes use `tabular-nums`. No all-caps labels, no eyebrows, no single accented word in headings.
- Layout: shadcn `sidebar` (256px) + breadcrumb header + left-aligned content; tables and forms, not cards; radius 4px on controls and 6px on dialogs; hairline borders, shadows only on popovers and dialogs; no gradients.
- The one memorable element: the **roster grid** (Milestone 3): employee rows × days, shift chips coloured from a fixed 8-colour ramp per shift, cross-midnight shifts drawn as bars spilling into the next day so `"30:00"` is visible. Everything else stays quiet.
- Motion: only what answers a click (sheet/dialog open, toast). Respect `prefers-reduced-motion` (shadcn default).
- Copy: sentence case, verbs on buttons ("Add employee", "Assign schedule", "Lock September"), errors say what happened and how to fix it, empty states invite the first action. Use `design:ux-copy` when writing screen text and `design:accessibility-review` before a milestone closes.
- Review against the generic defaults before building each screen family: no cream/serif/terracotta, no dark+acid accent, no broadsheet, no card kit, no middle-dot metadata, no arrows in links.

### Skills and tools per task

| When | Use |
|---|---|
| Before any Laravel/Inertia API call you are not certain of | Boost `search-docs` (scope with `packages`) |
| After a migration runs | Boost `database-schema` (filter by table) to confirm columns, indexes, FKs |
| Writing PHP | `laravel-best-practices` rule files mapped to the concern (eloquent, migrations, routing, security, validation, queue-jobs) |
| Writing tests | `testing-best-practices` (endpoint-tests, test-data, isolation, naming) |
| Writing JSX styling | `tailwindcss-development`; shadcn MCP `get_component` / `get_block` for primitives and the sidebar/login blocks |
| New screen family | `frontend-design:frontend-design` pass against the brief above, screenshot review through the Browser pane on `http://localhost:43080` |
| A convention becomes settled | Boost `record-rule` (glob, title, note) so `.ai/rules` carries it |

### Decisions kept for you at execution time (5–10 lines each, flagged `YOUR CALL` in the task)

1. `Preset::permissions()` bundles (Task 5): which permissions Admin, HR and Viewer carry.
2. Invite lifetime and stale-invite handling (Task 7): default 7 days, re-send allowed, un-accepted users cannot log in.

### Database role rules (from the architecture review of the installed framework)

- The app role `khronoz_app` is created by provisioning (`docker/pgsql/20-create-app-role.sh` on a fresh volume; a documented one-liner for existing volumes and production), never by a migration: a password inside DDL would surface in `--pretend`, `DB::listen`, Telescope and `log_statement`.
- The first migration holds only per-database, secret-free, idempotent grants; every later table inherits CRUD through default privileges and REVOKEs what it must.
- `DB_OWNER_*` exists only in dev `.env` and the deploy/migrate step. It must be unset in the Octane and Horizon runtime environment, or `DB::connection('owner')` would bypass every REVOKE.
- Seeders never call `truncate()` (TRUNCATE is not granted); they use `firstOrCreate` or `delete()`. Tests seed the platform row through `db:seed` on the app connection once per process, not through `--seed`, which would run as the owner.
- `php artisan test --parallel` is unsupported until `ParallelTesting::setUpTestCase` repoints the `owner` connection; run the suite serially.
- In production the owner role must own the database (`CREATE DATABASE khronoz OWNER khronoz_owner`), or on PG15+ it cannot create in `public`.

---

# Milestone 1 — Foundation

**Exit criteria:** a superuser logs in, creates an agency, enters it, invites an HR user with chosen permissions; the HR user accepts through the emailed link, logs in and sees only their agency; every constraint in the agencies and users DDL has a refusing test; the app connects as `khronoz_app` and cannot create tables; `php artisan test --compact`, `npm run types`, `npm run lint`, `npm run build` pass.

### Task 1: Align the design docs with today's decisions

**Files:**
- Modify: `docs/design/README.md`, `docs/design/00-principles.md`, `docs/design/02-access.md`, `docs/design/03-devices.md` → rename to `docs/design/03-terminals.md`, `docs/design/04-scheduling.md`, `docs/design/06-attendance.md`, `docs/design/07-constraints.md`

**Interfaces:**
- Produces: the vocabulary every later task uses: `Terminal`/`terminals`/`terminal_id`, `users.permissions jsonb`, `Permission` enum values listed below.

- [ ] **Step 1: Rename the biometric device**

Run (BSD sed; `\b` does not work, use `[[:<:]]`/`[[:>:]]`):

```bash
cd /Users/joowdx/Projects/khronoz/docs/design
git mv 03-devices.md 03-terminals.md
sed -i '' -e 's/[[:<:]]Devices[[:>:]]/Terminals/g' -e 's/[[:<:]]devices[[:>:]]/terminals/g' -e 's/[[:<:]]Device[[:>:]]/Terminal/g' -e 's/[[:<:]]device_id[[:>:]]/terminal_id/g' -e 's/03-devices\.md/03-terminals.md/g' README.md 00-principles.md 02-access.md 03-terminals.md 04-scheduling.md 05-calendar.md 06-attendance.md 07-constraints.md
grep -n "device" *.md
```

Expected: remaining hits are only the phrases "device user id", "as reported by device", "what the device saw/recorded", "device clock skew", "the value the attlog carries", "Devices and vendors" row text and `source = 'device'` (the attlog source enum stays `device`, it names the origin of the row, not the model). Fix any other hit by hand. In `03-terminals.md` set the ERD entity to `TERMINALS`, the mermaid relations `AGENCIES ||--o{ TERMINALS`, `UNITS |o--o{ TERMINALS`, and the trigger comment in 07 `agency_not_platform on employees, units, terminals, groups`.

- [ ] **Step 2: Replace the role enum with permissions in 02 and 07**

In `02-access.md` ERD replace `enum role` with `json permissions "array of permission strings, checked by permissions_valid()"` and rewrite rule 4:

```markdown
4. Access is a set of permissions on the user, not a role. `permissions` is a jsonb array of strings from one PHP enum, checked by `permissions_valid()` (array, strings, distinct). v1 set, `manage` implies `view`: `agency.manage`, `users.manage`, `organization.view|manage`, `scheduling.view|manage`, `calendar.view|manage`, `terminals.view|manage`, `ledgers.view|manage|attest`. Presets (Admin, HR, Viewer) are convenience bundles in code for the invite form, never stored. Superuser is the platform flag, supervisor and head come from `Unit.head_id`; the attestation role `hr` means any user of the agency holding `ledgers.attest`.
```

In `07-constraints.md` users block add:

```sql
permissions jsonb NOT NULL DEFAULT '[]'
CHECK (permissions_valid(permissions))                              -- array of distinct strings; the allowed set is the PHP enum
UNIQUE (id, agency_id)                                             -- target for the attestation FK
```

and the function:

```sql
CREATE FUNCTION permissions_valid(permissions jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_typeof(permissions) = 'array'
       AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(permissions) e WHERE jsonb_typeof(e) <> 'string')
       AND jsonb_array_length(permissions) = (SELECT count(DISTINCT e) FROM jsonb_array_elements_text(permissions) e);
$$;
```

In `06-attendance.md` Attestation rule 3 replace "`hr`: any user with the HR role" with "`hr`: any user of the agency holding `ledgers.attest`".

In `00-principles.md` principle 7 replace "Superusers bypass it." with "Platform users act inside a chosen agency, so the scope has one code path (02-access.md rule 3)." — 00 and 02 currently contradict each other.

- [ ] **Step 3: Record the decisions in the README**

Append to "Open decisions":

```markdown
16. Decided 2026-09-09: the biometric device model is `Terminal` (`terminals`, `terminal_id`). Passport's device-authorization model keeps `Device`/`devices`, installed in 4d067d4. Decision 3 is superseded.
17. Decided 2026-09-09: permissions instead of a role enum. `users.permissions` is a jsonb array of strings from one PHP enum with a Postgres shape check; presets are code, not rows. Decision in 02-access.md rule 4 supersedes "roles are a single enum".
18. Decided 2026-09-09: auth is hand-rolled on framework primitives (Fortify's stable line stops at Laravel 12); invite-only logins; Google and Apple sign-in through Socialite only for already-invited emails.
```

Also update the README table row `03-devices.md | raw timelogs in | Device, Enrollment, Template, Sync, Timelog` to `03-terminals.md | raw timelogs in | Terminal, Enrollment, Template, Sync, Timelog`.

- [ ] **Step 4: Verify and commit**

```bash
grep -rn "[[:<:]]Device[[:>:]]\|device_id\|enum role" docs/design/ ; echo "exit $?"
git add -A docs/design && git commit -m "docs: rename Device to Terminal, permissions replace roles"
```

Expected: the grep prints nothing (exit 1).

### Task 2: Front-end toolchain, tokens and app shell

**Files:**
- Modify: `package.json`, `vite.config.js`, `tsconfig.json`, `resources/css/app.css`, `resources/views/app.blade.php`, `.gitignore`, `composer.json`
- Create: `components.json`, `eslint.config.js`, `.prettierrc`, `resources/js/lib/utils.ts`, `resources/js/components/ui/*` (generated), `resources/js/types/index.d.ts`, `resources/js/hooks/use-can.ts`, `resources/js/layouts/app-layout.tsx`, `resources/js/layouts/auth-layout.tsx`, `resources/js/components/app-sidebar.tsx`, `resources/js/components/nav-user.tsx`, `resources/js/components/page-header.tsx`, `resources/js/components/input-error.tsx`, `resources/js/components/empty-state.tsx`

**Interfaces:**
- Produces: `SharedProps`, `AuthUser`, `Agency`, `Permission` TS types; `useCan(permission)`; `AppLayout({ breadcrumbs, children })`; `AuthLayout({ title, description, children })`; `PageHeader({ title, description, actions })`; `InputError({ message })`; `EmptyState({ title, description, action })`. Later tasks import exactly these.

- [ ] **Step 1: Install approved dependencies**

```bash
cd /Users/joowdx/Projects/khronoz
composer require laravel/wayfinder --no-interaction
npm i -D @laravel/vite-plugin-wayfinder prettier prettier-plugin-tailwindcss eslint @eslint/js typescript-eslint eslint-plugin-react-hooks globals
npm i radix-ui class-variance-authority clsx tailwind-merge lucide-react tw-animate-css sonner cmdk react-day-picker @tanstack/react-table
```

Expected: `composer show laravel/wayfinder` prints v0.1.x; `package.json` lists the packages.

- [ ] **Step 2: Wire Wayfinder and the design font into Vite**

`vite.config.js` plugins (the Wayfinder plugin is `enforce: 'pre'`, so its position is irrelevant; the starter kit puts it last):

```js
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
// ...
plugins: [
    laravel({
        input: ['resources/css/app.css', 'resources/js/app.tsx'],
        ssr: 'resources/js/ssr.tsx',
        refresh: true,
        fonts: [bunny('Public Sans', { weights: [400, 500, 600, 700] })],
    }),
    tailwindcss(),
    react(),
    inertia(),
    wayfinder({ formVariants: true }),
],
```

Append to `.gitignore`:

```
/resources/js/actions
/resources/js/routes
/resources/js/wayfinder
```

Add to `composer.json` scripts: `"migrate": ["@php artisan migrate --database=owner"]` (used from Task 3 on) and to `package.json` scripts: `"lint": "eslint resources/js"`, `"format": "prettier --write resources/js"`, `"pretypes": "php artisan wayfinder:generate --with-form"` (so `tsc` always sees the generated files), `"types": "tsc --noEmit"` (exists).

Run `php artisan wayfinder:generate --with-form` and confirm `resources/js/routes/index.ts` exists.

- [ ] **Step 3: Initialise shadcn/ui from the official registry**

Create `components.json` (shadcn reads it instead of prompting):

```json
{
    "$schema": "https://ui.shadcn.com/schema.json",
    "style": "new-york",
    "rsc": false,
    "tsx": true,
    "tailwind": { "config": "", "css": "resources/css/app.css", "baseColor": "neutral", "cssVariables": true, "prefix": "" },
    "aliases": { "components": "@/components", "utils": "@/lib/utils", "ui": "@/components/ui", "lib": "@/lib", "hooks": "@/hooks" },
    "iconLibrary": "lucide"
}
```

```bash
npx shadcn@latest add button input label textarea select checkbox switch radio-group badge table tabs tooltip dialog alert-dialog sheet dropdown-menu command popover calendar separator skeleton sonner breadcrumb sidebar avatar alert pagination scroll-area toggle-group card --yes --overwrite
```

Expected: files under `resources/js/components/ui/`, `resources/js/lib/utils.ts` with `cn()`, `resources/js/hooks/use-mobile.ts`, and `resources/css/app.css` gains the `:root`/`.dark` variable blocks plus `@theme inline`. The CLI detects the Laravel framework from `composer.json`, finds the CSS file by its `@import 'tailwindcss'` line and reads the `@` alias from `tsconfig.json`; no `src/` or `tsconfig.app.json` is needed. `tsconfig.json` has `noUncheckedIndexedAccess` and `verbatimModuleSyntax` on, which the starter kit does not: expect a few `tsc` errors in the generated `sidebar`, `calendar`, `chart` or `carousel` components and patch them in place (they are project files).

- [ ] **Step 4: Replace the generated tokens with the khronoz palette**

In `resources/css/app.css` keep shadcn's `@theme inline` mapping and `@custom-variant dark (&:is(.dark *))`, and set the variables to the brief. Colours as hex (Tailwind 4 accepts them):

```css
@import 'tailwindcss';
@import 'tw-animate-css';
@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';

@custom-variant dark (&:is(.dark *));

:root {
    --radius: 0.25rem;
    --background: #f4f6f8;          --foreground: #14213d;
    --card: #ffffff;                --card-foreground: #14213d;
    --popover: #ffffff;             --popover-foreground: #14213d;
    --primary: #0b6b5d;             --primary-foreground: #ffffff;
    --secondary: #e9edf1;           --secondary-foreground: #14213d;
    --muted: #eef1f4;               --muted-foreground: #5b6472;
    --accent: #e3f2ee;              --accent-foreground: #0b6b5d;
    --destructive: #b42318;         --destructive-foreground: #ffffff;
    --warning: #b54708;             --warning-foreground: #ffffff;
    --border: #d9dee5;              --input: #d9dee5;              --ring: #0b6b5d;
    --sidebar: #ffffff;             --sidebar-foreground: #14213d;
    --sidebar-primary: #0b6b5d;     --sidebar-primary-foreground: #ffffff;
    --sidebar-accent: #e3f2ee;      --sidebar-accent-foreground: #0b6b5d;
    --sidebar-border: #d9dee5;      --sidebar-ring: #0b6b5d;
    --chart-1: #0b6b5d; --chart-2: #1d4ed8; --chart-3: #b54708; --chart-4: #7a3e9d; --chart-5: #b42318;
}

.dark {
    --background: #0e1420;          --foreground: #e6eaf0;
    --card: #151d2b;                --card-foreground: #e6eaf0;
    --popover: #151d2b;             --popover-foreground: #e6eaf0;
    --primary: #3ea98f;             --primary-foreground: #0e1420;
    --secondary: #1c2636;           --secondary-foreground: #e6eaf0;
    --muted: #1c2636;               --muted-foreground: #9aa4b2;
    --accent: #123a33;              --accent-foreground: #3ea98f;
    --destructive: #f97066;         --destructive-foreground: #0e1420;
    --warning: #fdb022;             --warning-foreground: #0e1420;
    --border: #2a3446;              --input: #2a3446;              --ring: #3ea98f;
    --sidebar: #151d2b;             --sidebar-foreground: #e6eaf0;
    --sidebar-primary: #3ea98f;     --sidebar-primary-foreground: #0e1420;
    --sidebar-accent: #123a33;      --sidebar-accent-foreground: #3ea98f;
    --sidebar-border: #2a3446;      --sidebar-ring: #3ea98f;
}

@theme inline {
    --font-sans: 'Public Sans', ui-sans-serif, system-ui, sans-serif;
    --color-warning: var(--warning);
    --color-warning-foreground: var(--warning-foreground);
    /* keep every --color-* line shadcn generated here */
}

@layer base {
    * { @apply border-border outline-ring/50; }
    body { @apply bg-background text-foreground; font-size: 14px; line-height: 1.45; }
    time, .tnum { font-variant-numeric: tabular-nums; }
}
```

`resources/views/app.blade.php`: change the body class to `class="min-h-svh bg-background text-foreground"` and add `class="antialiased"` stays on `<html>`; dark mode is class-based (`.dark` on `<html>` toggled later from user preference; no toggle in M1).

- [ ] **Step 5: Shared types and the permission hook**

`resources/js/types/index.d.ts`:

```ts
export type Permission =
    | 'agency.manage' | 'users.manage'
    | 'organization.view' | 'organization.manage'
    | 'scheduling.view' | 'scheduling.manage'
    | 'calendar.view' | 'calendar.manage'
    | 'terminals.view' | 'terminals.manage'
    | 'ledgers.view' | 'ledgers.manage' | 'ledgers.attest';

export interface AuthUser { id: string; name: string; email: string; permissions: Permission[]; platform: boolean; employee_id: string | null }
export interface Agency { id: string; code: string; name: string; platform: boolean }
export interface Flash { success?: string; error?: string }
export interface SharedProps { auth: { user: AuthUser | null }; agency: Agency | null; agencies: Agency[]; flash: Flash; [key: string]: unknown }
export interface BreadcrumbItem { title: string; href?: string }
```

`resources/js/hooks/use-can.ts`:

```ts
import { usePage } from '@inertiajs/react';
import type { Permission, SharedProps } from '@/types';

const implied: Partial<Record<Permission, Permission>> = {
    'organization.manage': 'organization.view', 'scheduling.manage': 'scheduling.view',
    'calendar.manage': 'calendar.view', 'terminals.manage': 'terminals.view', 'ledgers.manage': 'ledgers.view',
};

export function useCan(): (permission: Permission) => boolean {
    const { auth } = usePage<SharedProps>().props;
    return (permission) => {
        const user = auth.user;
        if (!user) return false;
        if (user.platform) return true;
        return user.permissions.some((held) => held === permission || implied[held] === permission);
    };
}
```

- [ ] **Step 6: Layouts and shell components**

`resources/js/layouts/app-layout.tsx` (uses shadcn sidebar primitives; `AppSidebar` nav filtered by `useCan`; flash rendered as `sonner` toasts):

```tsx
import { Link, usePage } from '@inertiajs/react';
import { Fragment, useEffect, type ReactNode } from 'react';
import { Toaster, toast } from 'sonner';
import { AppSidebar } from '@/components/app-sidebar';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as Crumb, SharedProps } from '@/types';

export default function AppLayout({ breadcrumbs = [], children }: { breadcrumbs?: Crumb[]; children: ReactNode }) {
    const { flash } = usePage<SharedProps>().props;
    useEffect(() => {
        if (flash.success) toast.success(flash.success);
        if (flash.error) toast.error(flash.error);
    }, [flash]);

    return (
        <SidebarProvider>
            <AppSidebar />
            <SidebarInset>
                <header className="flex h-12 items-center gap-2 border-b px-4">
                    <SidebarTrigger className="-ml-1" />
                    <Separator orientation="vertical" className="mr-2 h-4" />
                    <Breadcrumb>
                        <BreadcrumbList>
                            {breadcrumbs.map((crumb, index) => (
                                <Fragment key={crumb.title}>
                                    {index > 0 && <BreadcrumbSeparator />}
                                    <BreadcrumbItem>
                                        {crumb.href ? <BreadcrumbLink asChild><Link href={crumb.href}>{crumb.title}</Link></BreadcrumbLink> : <BreadcrumbPage>{crumb.title}</BreadcrumbPage>}
                                    </BreadcrumbItem>
                                </Fragment>
                            ))}
                        </BreadcrumbList>
                    </Breadcrumb>
                </header>
                <main className="flex flex-1 flex-col gap-6 p-6">{children}</main>
                <Toaster position="bottom-right" />
            </SidebarInset>
        </SidebarProvider>
    );
}
```

`resources/js/components/app-sidebar.tsx`: `Sidebar` with header slot `<AgencySwitcher />` (Task 8 adds it; in this task render the agency name from `agency` shared prop), nav groups: Workspace → Dashboard (`dashboard()`), Users (`users.index()` when `useCan()('users.manage')`); Platform → Agencies (`platform.agencies.index()` when `auth.user.platform`); footer `<NavUser />` with name, email and a logout `<Form action={logout()} method="post">` button. Icons: `LayoutDashboard`, `Users`, `Building2`, `LogOut` from lucide.

`resources/js/layouts/auth-layout.tsx`: centred column max-w-sm, wordmark "khronoz" in `font-semibold tracking-tight`, `title`, `description`, children.

`resources/js/components/page-header.tsx`:

```tsx
import type { ReactNode } from 'react';
export function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4">
            <div><h1 className="text-xl font-semibold tracking-tight">{title}</h1>{description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}</div>
            {actions && <div className="flex gap-2">{actions}</div>}
        </div>
    );
}
```

`resources/js/components/input-error.tsx`: `({ message }) => message ? <p className="text-sm text-destructive">{message}</p> : null`.

`resources/js/components/empty-state.tsx`: bordered dashed box with `title`, `description`, optional `action` node.

- [ ] **Step 7: Lint and format config, then verify**

`eslint.config.js` (flat): `@eslint/js` recommended, `typescript-eslint` recommended, `react-hooks` recommended-latest, ignores `resources/js/{actions,routes,wayfinder,components/ui}/**`, `bootstrap/ssr/**`, `public/build/**`. `.prettierrc`: `{ "singleQuote": true, "tabWidth": 4, "printWidth": 120, "plugins": ["prettier-plugin-tailwindcss"] }`.

```bash
npm run format && npm run lint && npm run types && npm run build
```

Expected: all exit 0 (welcome page still renders; SSR bundle builds).

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat: front-end toolchain, design tokens and app shell"
```

### Task 3: Database roles, connections and extension

**Files:**
- Modify: `config/database.php`, `.env.example`, `.env`, `compose.yaml` (targeted lines only, never a rewrite), `tests/TestCase.php`
- Create: `docker/pgsql/20-create-app-role.sh`, `database/migrations/0000_00_00_000001_prepare_database.php`, `app/Listeners/EnsureMigrationsRunAsOwner.php`, `tests/Feature/Database/AppRoleTest.php`

**Interfaces:**
- Produces: connection `owner`; role `khronoz_app` (provisioned, not migrated) with `SELECT, INSERT, UPDATE, DELETE` on every present and future table in `public` plus `USAGE, SELECT` on sequences; extension `btree_gist`; a guard that refuses `php artisan migrate` on any connection but `owner`; `TestCase::assertDatabaseRefuses(string $sqlstate, callable $fn): void`; `composer migrate`.

- [ ] **Step 1: Add the owner connection**

In `config/database.php` after `'pgsql' => [...]` add:

```php
// Migrations and schema changes run as the database owner. The application
// itself connects as `khronoz_app`, which cannot alter schema or bypass the
// column-level grants declared in the migrations (docs/design/07-constraints.md).
'owner' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'khronoz'),
    'username' => env('DB_OWNER_USERNAME', 'sail'),
    'password' => env('DB_OWNER_PASSWORD', 'password'),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'prefer'),
],
```

`.env.example` and `.env`: `DB_USERNAME=khronoz_app`, `DB_PASSWORD=password`, add `DB_OWNER_USERNAME=sail`, `DB_OWNER_PASSWORD=password` under the comment `# Migrations run as the owner (composer migrate). Leave DB_OWNER_* unset where Octane and Horizon run.`

`compose.yaml`, pgsql service, targeted edits only: `PGPASSWORD: '${DB_OWNER_PASSWORD:-password}'`, `POSTGRES_USER: '${DB_OWNER_USERNAME:-sail}'`, `POSTGRES_PASSWORD: '${DB_OWNER_PASSWORD:-password}'`, healthcheck `-U ${DB_OWNER_USERNAME:-sail}`; add two environment lines `APP_DB_USERNAME: '${DB_USERNAME:-khronoz_app}'` and `APP_DB_PASSWORD: '${DB_PASSWORD:-password}'`; add one volume line `- './docker/pgsql/20-create-app-role.sh:/docker-entrypoint-initdb.d/20-create-app-role.sh'`.

`docker/pgsql/20-create-app-role.sh` (runs once on a fresh volume, after `10-create-testing-database.sql`):

```sh
#!/bin/sh
# Creates the application role. Roles are cluster-wide, so one script serves
# both the khronoz and testing databases. Grants live in the first migration.
set -e
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" <<SQL
DO \$\$ BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${APP_DB_USERNAME}') THEN
        CREATE ROLE "${APP_DB_USERNAME}" LOGIN PASSWORD '${APP_DB_PASSWORD}';
    END IF;
END \$\$;
SQL
```

The existing `khronoz-pgsql` volume already ran its init scripts, so recreate the container to pick up the mount and run the script once by hand (no data is lost; `docker compose down -v` would also work but destroys the dev database):

```bash
docker compose up -d pgsql && docker exec khronoz-pgsql sh /docker-entrypoint-initdb.d/20-create-app-role.sh
```

Document both paths (fresh volume, existing volume, production one-liner `CREATE ROLE khronoz_app LOGIN PASSWORD '…'`) in the README in Task 11.

- [ ] **Step 2: Write the failing privilege test**

`tests/TestCase.php`:

```php
<?php

namespace Tests;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use LazilyRefreshDatabase;

    /** Migrations run as the owner; tests then query as the app role. */
    protected function migrateFreshUsing(): array
    {
        return [...parent::migrateFreshUsing(), '--database' => 'owner'];
    }

    /** Assert Postgres refused the statement with the given SQLSTATE. */
    protected function assertDatabaseRefuses(string $sqlstate, Closure $statement): void
    {
        try {
            $statement();
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->getCode(), "expected SQLSTATE {$sqlstate}, got {$e->getCode()}: {$e->getMessage()}");

            return;
        }

        $this->fail("expected the database to refuse with SQLSTATE {$sqlstate}");
    }
}
```

`tests/Feature/Database/AppRoleTest.php`:

```php
<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppRoleTest extends TestCase
{
    public function test_app_connection_uses_the_app_role(): void
    {
        $this->assertSame('khronoz_app', DB::selectOne('select current_user as name')->name);
    }

    public function test_app_role_cannot_create_tables(): void
    {
        $this->assertDatabaseRefuses('42501', fn () => DB::statement('create table smuggled (id int)'));
    }

    public function test_app_role_can_read_and_write_migrated_tables(): void
    {
        DB::table('sessions')->insert(['id' => 'probe', 'payload' => '', 'last_activity' => 0]);

        $this->assertSame(1, DB::table('sessions')->where('id', 'probe')->count());
    }

    public function test_btree_gist_is_installed(): void
    {
        $this->assertSame(1, DB::table('pg_extension')->where('extname', 'btree_gist')->count());
    }

    public function test_migrations_refuse_any_connection_but_owner(): void
    {
        $this->assertSame('pgsql', DB::getDefaultConnection());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer migrate');

        (new EnsureMigrationsRunAsOwner)->handle(new MigrationsStarted('up'));
    }
}
```

(`MigrationsStarted` is `Illuminate\Database\Events\MigrationsStarted`; confirm its constructor arguments with Boost `search-docs` or the vendor source before writing.)

- [ ] **Step 3: Run it to verify it fails**

```bash
php artisan test --compact tests/Feature/Database/AppRoleTest.php
```

Expected: FAIL (connection as `khronoz_app` has no privileges yet, or the listener class is missing).

- [ ] **Step 4: Provision the role, write the guard and the migration**

Run the provisioning command from Step 1 (`docker compose up -d pgsql && docker exec …`) and confirm: `docker exec khronoz-pgsql psql -U sail -d khronoz -tAc "select 1 from pg_roles where rolname = 'khronoz_app'"` prints `1`.

`app/Listeners/EnsureMigrationsRunAsOwner.php` (`php artisan make:listener EnsureMigrationsRunAsOwner --event=MigrationsStarted --no-interaction`; auto-discovered):

```php
final class EnsureMigrationsRunAsOwner
{
    /** A habitual `php artisan migrate` would run as khronoz_app and fail half-way. */
    public function handle(MigrationsStarted $event): void
    {
        if (DB::getDefaultConnection() !== 'owner') {
            throw new RuntimeException('Migrations must run on the owner connection: use `composer migrate` (php artisan migrate --database=owner).');
        }
    }
}
```

`database/migrations/0000_00_00_000001_prepare_database.php` (secret-free, idempotent, per database):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Runs on the owner connection. The application role is provisioned outside
     * migrations (docker/pgsql/20-create-app-role.sh, README); this migration
     * gives it row privileges on every present and future table so later
     * migrations need no grants, only the REVOKEs on timelogs, syncs and
     * attestations. Default privileges follow the role that runs migrations.
     */
    public function up(): void
    {
        $role = config('database.connections.pgsql.username');

        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $role)) {
            throw new RuntimeException('DB_USERNAME must be a plain lowercase identifier.');
        }

        if (DB::selectOne('select 1 as present from pg_roles where rolname = ?', [$role]) === null) {
            throw new RuntimeException("Role {$role} does not exist. Create it first: see README, Database roles.");
        }

        $database = DB::getDatabaseName();
        $owner = DB::selectOne('select current_user as name')->name;

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("GRANT CONNECT ON DATABASE \"{$database}\" TO \"{$role}\"");
        DB::statement("GRANT USAGE ON SCHEMA public TO \"{$role}\"");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO \"{$role}\"");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO \"{$role}\"");
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO \"{$role}\"");
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO \"{$role}\"");
    }

    /** Grants are harmless to keep and other databases may share the role: intentionally a no-op. */
    public function down(): void {}
};
```

- [ ] **Step 5: Migrate and run the test**

```bash
composer migrate && php artisan test --compact tests/Feature/Database/AppRoleTest.php
```

Expected: 5 tests PASS. Then run the whole suite: `php artisan test --compact` (ExampleTest still passes). Also confirm the guard by hand: `php artisan migrate` (no flag) must abort with the `composer migrate` message.

- [ ] **Step 6: Record the rule and commit**

Use Boost `record-rule` with glob `database/migrations/**`, title "Migrations run on the owner connection", note: "Run `composer migrate` (= `php artisan migrate --database=owner`); a MigrationsStarted listener refuses any other connection. The app connection is the `khronoz_app` role, provisioned by docker/pgsql/20-create-app-role.sh, never by a migration; `0000_00_00_000001_prepare_database` grants it CRUD on future tables via default privileges. Tables that must be immutable REVOKE in their own migration. Seeders never truncate(). Tests override migrateFreshUsing() and migrateDatabases() in tests/TestCase.php."

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: app database role, owner connection, btree_gist"
```

### Task 4: Agencies and the platform row

**Files:**
- Create: `database/migrations/0000_00_00_000002_create_agencies_table.php`, `app/Models/Agency.php`, `app/Models/Scopes/NotPlatformScope.php`, `database/factories/AgencyFactory.php`, `database/seeders/PlatformSeeder.php`, `tests/Feature/Models/AgencyTest.php`
- Modify: `database/seeders/DatabaseSeeder.php`, `tests/TestCase.php`

**Interfaces:**
- Produces: `Agency` model (`id, code, name, platform, settings, created_at, updated_at`), `Agency::platform(): Agency`, `Agency::factory()->platform()`, `PlatformSeeder` (idempotent, `code = 'platform'`, `name = 'khronoz'`), base test seeding of the platform row.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Models/AgencyTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Scopes\NotPlatformScope;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgencyTest extends TestCase
{
    public function test_platform_row_is_seeded_once(): void
    {
        $this->assertTrue(Agency::platform()->platform);
        $this->assertSame(1, Agency::withoutGlobalScope(NotPlatformScope::class)->where('platform', true)->count());
    }

    public function test_second_platform_row_is_refused(): void
    {
        $this->assertDatabaseRefuses('23505', fn () => Agency::factory()->platform()->create());
    }

    public function test_platform_row_cannot_be_deleted(): void
    {
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('agencies')->where('platform', true)->delete());
    }

    public function test_platform_flag_cannot_change(): void
    {
        $agency = Agency::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('agencies')->where('id', $agency->id)->update(['platform' => true]));
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('agencies')->where('platform', true)->update(['platform' => false]));
    }

    public function test_agency_lists_hide_the_platform_row(): void
    {
        Agency::factory()->count(2)->create();

        $this->assertSame(2, Agency::count());
        $this->assertFalse(Agency::query()->pluck('platform')->contains(true));
    }

    public function test_code_is_unique(): void
    {
        Agency::factory()->create(['code' => 'DOH']);

        $this->assertDatabaseRefuses('23505', fn () => Agency::factory()->create(['code' => 'DOH']));
    }

    public function test_settings_must_be_an_object(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => DB::table('agencies')->insert([
            'id' => '01J00000000000000000000000', 'code' => 'X', 'name' => 'X', 'settings' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test --compact tests/Feature/Models/AgencyTest.php
```

Expected: FAIL, class `App\Models\Agency` not found.

- [ ] **Step 3: Migration**

```bash
php artisan make:migration create_agencies_table --no-interaction
mv database/migrations/*_create_agencies_table.php database/migrations/0000_00_00_000002_create_agencies_table.php
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('platform')->default(false);
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX agencies_platform ON agencies (platform) WHERE platform');
        DB::statement("ALTER TABLE agencies ADD CONSTRAINT agencies_settings_object CHECK (jsonb_typeof(settings) = 'object')");

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION agencies_platform_row() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.platform THEN
                        RAISE EXCEPTION 'the platform agency cannot be deleted';
                    END IF;
                    RETURN OLD;
                END IF;
                IF NEW.platform IS DISTINCT FROM OLD.platform THEN
                    RAISE EXCEPTION 'platform cannot change after insert';
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER agencies_platform_row
                BEFORE UPDATE OF platform OR DELETE ON agencies
                FOR EACH ROW EXECUTE FUNCTION agencies_platform_row();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('agencies');
        DB::statement('DROP FUNCTION IF EXISTS agencies_platform_row()');
    }
};
```

- [ ] **Step 4: Scope, model, factory, seeder**

`app/Models/Scopes/NotPlatformScope.php` (`php artisan make:scope NotPlatformScope --no-interaction`):

```php
public function apply(Builder $builder, Model $model): void
{
    $builder->where($model->qualifyColumn('platform'), false);
}
```

`app/Models/Agency.php`:

```php
<?php

namespace App\Models;

use App\Models\Scopes\NotPlatformScope;
use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant. Exactly one row has `platform` true; it owns the shared rows
 * and the superusers, and every list hides it (NotPlatformScope).
 */
#[Fillable(['code', 'name', 'settings'])]
#[ScopedBy(NotPlatformScope::class)]
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['platform' => 'boolean', 'settings' => 'array'];
    }

    /** The platform row, hidden from every other query. */
    public static function platform(): self
    {
        return static::withoutGlobalScope(NotPlatformScope::class)->where('platform', true)->firstOrFail();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
```

`database/factories/AgencyFactory.php`: definition `['code' => strtoupper(fake()->unique()->lexify('????')), 'name' => fake()->company(), 'platform' => false, 'settings' => []]`; state `platform()` → `['code' => 'platform', 'name' => 'khronoz', 'platform' => true]`.

`database/seeders/PlatformSeeder.php`:

```php
public function run(): void
{
    Agency::withoutGlobalScope(NotPlatformScope::class)->firstOrCreate(['platform' => true], ['code' => 'platform', 'name' => 'khronoz']);
}
```

`DatabaseSeeder::run()`: `$this->call(PlatformSeeder::class);` then, only when `app()->environment('local')`, create the dev superuser (Task 5 adds the user part).

Base seeding for tests: do **not** use `--seed` or `#[Seed]`; they run inside `migrate:fresh --database=owner` and would seed as the owner. Override `migrateDatabases()` in `tests/TestCase.php` so the platform row is seeded once per process, on the app connection, before the per-test transactions begin:

```php
protected function migrateDatabases(): void
{
    parent::migrateDatabases();

    $this->artisan('db:seed', ['--class' => PlatformSeeder::class, '--no-interaction' => true]);
}

protected function platform(): Agency
{
    return Agency::platform();
}
```

Task 5 adds `actingAsPlatform(?Agency $enter = null): User` (sets `session(['agency' => $enter->id])` when given) and `actingAsAgency(Agency $agency, Permission ...$permissions): User`; Task 6 adds `withTenant(Agency $agency): static`.

- [ ] **Step 5: Run the tests**

```bash
composer migrate -- --force && php artisan test --compact tests/Feature/Models/AgencyTest.php
```

Expected: 7 PASS. Confirm the DDL with Boost `database-schema` filter `agencies` (partial index `agencies_platform`, check `agencies_settings_object`).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: agencies table with the guarded platform row"
```

### Task 5: Users carry an agency, permissions and an employee link

**Files:**
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php` (local-only, edited in place), `app/Models/User.php`, `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php`, `app/Providers/AppServiceProvider.php`
- Create: `app/Enums/Permission.php`, `app/Enums/Preset.php`, `tests/Feature/Models/UserTest.php`, `tests/Unit/Enums/PermissionTest.php`

**Interfaces:**
- Produces: `Permission` (string enum, `label()`, `group()`, `implies(): array<Permission>`, `grants(Permission): bool`), `Preset` (`Admin`, `Hr`, `Viewer`; `permissions(): array<Permission>`, `label()`), `User::allows(Permission): bool`, `User::isPlatform(): bool` (defined here as `agency->platform` through an unscoped relation; Task 7 keeps the signature), `User::factory()->forAgency(Agency)`, `->platform()`, `->permissions(Permission ...)`, `->preset(Preset)`, `->invited()`. Gate abilities named by permission value. Dev superuser `superuser@khronoz.test` / `password` in local seeding.

- [ ] **Step 1: Failing tests**

`tests/Unit/Enums/PermissionTest.php`:

```php
public function test_manage_grants_its_view(): void
{
    $this->assertTrue(Permission::ManageScheduling->grants(Permission::ViewScheduling));
    $this->assertFalse(Permission::ViewScheduling->grants(Permission::ManageScheduling));
    $this->assertTrue(Permission::AttestLedgers->grants(Permission::AttestLedgers));
}

public function test_presets_only_contain_known_permissions(): void
{
    foreach (Preset::cases() as $preset) {
        $this->assertNotEmpty($preset->permissions());
        $this->assertContainsOnlyInstancesOf(Permission::class, $preset->permissions());
    }
    $this->assertEqualsCanonicalizing(Permission::cases(), Preset::Admin->permissions());
}
```

`tests/Feature/Models/UserTest.php`:

```php
public function test_permissions_must_be_distinct_strings(): void
{
    $agency = Agency::factory()->create();
    $row = fn (string $permissions) => ['id' => (string) Str::ulid(), 'agency_id' => $agency->id, 'name' => 'x', 'email' => Str::random().'@x.test', 'password' => 'x', 'permissions' => $permissions, 'created_at' => now(), 'updated_at' => now()];

    $this->assertDatabaseRefuses('23514', fn () => DB::table('users')->insert($row('{"a":1}')));
    $this->assertDatabaseRefuses('23514', fn () => DB::table('users')->insert($row('[1]')));
    $this->assertDatabaseRefuses('23514', fn () => DB::table('users')->insert($row('["users.manage","users.manage"]')));
}

public function test_email_is_unique_regardless_of_case(): void
{
    User::factory()->create(['email' => 'Ana@Agency.gov.ph']);

    $this->assertDatabaseRefuses('23505', fn () => User::factory()->create(['email' => 'ana@agency.gov.ph']));
}

public function test_user_needs_an_agency(): void
{
    $this->assertDatabaseRefuses('23502', fn () => User::factory()->create(['agency_id' => null]));
}

public function test_allows_follows_held_and_implied_permissions(): void
{
    $user = User::factory()->permissions(Permission::ManageScheduling)->create();

    $this->assertTrue($user->allows(Permission::ManageScheduling));
    $this->assertTrue($user->allows(Permission::ViewScheduling));
    $this->assertFalse($user->allows(Permission::ManageUsers));
}

public function test_platform_user_passes_every_gate(): void
{
    $superuser = User::factory()->platform()->create();
    $this->assertTrue($superuser->isPlatform());
    $this->assertTrue(Gate::forUser($superuser)->allows(Permission::ManageUsers->value));

    $staff = User::factory()->create();
    $this->assertFalse($staff->isPlatform());
    $this->assertFalse(Gate::forUser($staff)->allows(Permission::ManageUsers->value));
}
```

Run: `php artisan test --compact tests/Unit/Enums tests/Feature/Models/UserTest.php` → FAIL (enums missing).

- [ ] **Step 2: Enums**

`php artisan make:enum Permission --string --no-interaction` and `Preset`.

```php
enum Permission: string
{
    case ManageAgency = 'agency.manage';
    case ManageUsers = 'users.manage';
    case ViewOrganization = 'organization.view';
    case ManageOrganization = 'organization.manage';
    case ViewScheduling = 'scheduling.view';
    case ManageScheduling = 'scheduling.manage';
    case ViewCalendar = 'calendar.view';
    case ManageCalendar = 'calendar.manage';
    case ViewTerminals = 'terminals.view';
    case ManageTerminals = 'terminals.manage';
    case ViewLedgers = 'ledgers.view';
    case ManageLedgers = 'ledgers.manage';
    case AttestLedgers = 'ledgers.attest';

    /** @return array<int, self> permissions this one carries with it */
    public function implies(): array
    {
        return match ($this) {
            self::ManageOrganization => [self::ViewOrganization],
            self::ManageScheduling => [self::ViewScheduling],
            self::ManageCalendar => [self::ViewCalendar],
            self::ManageTerminals => [self::ViewTerminals],
            self::ManageLedgers, self::AttestLedgers => [self::ViewLedgers],
            default => [],
        };
    }

    public function grants(self $wanted): bool
    {
        return $this === $wanted || in_array($wanted, $this->implies(), true);
    }

    public function group(): string
    {
        return ucfirst(explode('.', $this->value)[0]);
    }

    public function label(): string
    {
        return match ($this) {
            self::ManageAgency => 'Manage agency profile and settings',
            self::ManageUsers => 'Invite users and set permissions',
            self::ViewOrganization => 'View units and employees',
            self::ManageOrganization => 'Manage units, employees, deployments and groups',
            self::ViewScheduling => 'View shifts, schedules and rosters',
            self::ManageScheduling => 'Manage shifts, schedules and rosters',
            self::ViewCalendar => 'View holidays, suspensions, exemptions and overtime',
            self::ManageCalendar => 'Manage holidays, suspensions, exemptions and overtime',
            self::ViewTerminals => 'View terminals and timelogs',
            self::ManageTerminals => 'Manage terminals, enrollments and timelogs',
            self::ViewLedgers => 'View workdays and DTRs',
            self::ManageLedgers => 'Lock and unlock DTRs',
            self::AttestLedgers => 'Sign DTRs as HR',
        };
    }
}
```

`Preset` — **YOUR CALL** (5–10 lines): the `permissions()` match. Default to unblock: `Admin` = all cases; `Hr` = Manage Organization/Scheduling/Calendar/Terminals/Ledgers + AttestLedgers; `Viewer` = the five `View*` cases.

- [ ] **Step 3: Users migration edited in place**

Replace the `users` block in `0001_01_01_000000_create_users_table.php`:

```php
Schema::create('users', function (Blueprint $table) {
    $table->ulid('id')->primary();
    $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
    $table->ulid('employee_id')->nullable()->unique(); // paired FK to employees arrives with Milestone 2
    $table->string('name');
    $table->string('email');
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->jsonb('permissions')->default(DB::raw("'[]'::jsonb"));
    $table->timestamp('invited_at')->nullable();
    $table->rememberToken();
    $table->timestamps();
    $table->unique(['id', 'agency_id']);
});

DB::statement('CREATE UNIQUE INDEX users_email ON users (lower(email))');
DB::unprepared(<<<'SQL'
    CREATE FUNCTION permissions_valid(permissions jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
        SELECT jsonb_typeof(permissions) = 'array'
           AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(permissions) e WHERE jsonb_typeof(e) <> 'string')
           AND jsonb_array_length(permissions) = (SELECT count(DISTINCT e) FROM jsonb_array_elements_text(permissions) e);
    $$;
    ALTER TABLE users ADD CONSTRAINT users_permissions_valid CHECK (permissions_valid(permissions));
SQL);
```

`down()` additionally drops `permissions_valid(jsonb)`. Keep `resets` and `sessions` as they are.

- [ ] **Step 4: Model, factory, gates, seeder**

`app/Models/User.php` additions:

```php
#[Fillable(['agency_id', 'employee_id', 'name', 'email', 'password', 'permissions', 'invited_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'invited_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => AsEnumCollection::of(Permission::class),
        ];
    }

    /** Stored lower-cased: the unique index is on lower(email) and the password broker compares exact strings. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => Str::lower(trim($value)));
    }

    /** No tenant scope on User: authentication resolves users before any tenant exists (Task 6 explains). */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class)->withoutGlobalScope(NotPlatformScope::class);
    }

    public function isPlatform(): bool
    {
        return (bool) $this->agency?->platform;
    }

    public function allows(Permission $permission): bool
    {
        return $this->permissions->contains(fn (Permission $held) => $held->grants($permission));
    }
}
```

`UserFactory`: definition adds `'agency_id' => Agency::factory()`, `'permissions' => []`; states `platform()` → `['agency_id' => Agency::platform()->id]`, `forAgency(Agency $agency)` → `['agency_id' => $agency->id]`, `permissions(Permission ...$permissions)` → `['permissions' => $permissions]`, `preset(Preset $preset)` → `['permissions' => $preset->permissions()]`, `invited()` → `['invited_at' => now(), 'email_verified_at' => null]`.

`AppServiceProvider::boot()` add `$this->configureAuthorization();`:

```php
protected function configureAuthorization(): void
{
    Gate::before(fn (User $user) => $user->isPlatform() ? true : null);

    foreach (Permission::cases() as $permission) {
        Gate::define($permission->value, fn (User $user) => $user->allows($permission));
    }
}
```

`DatabaseSeeder` local branch: `User::factory()->platform()->create(['name' => 'Superuser', 'email' => 'superuser@khronoz.test'])` guarded by `User::where('email', ...)->doesntExist()`.

- [ ] **Step 5: Fresh migrate, run tests, commit**

```bash
php artisan migrate:fresh --database=owner --seed --force && php artisan test --compact
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: users belong to an agency and hold permissions"
```

Expected: all green; `database-schema` filter `users` shows `users_email` unique index and `users_permissions_valid` check.

### Task 6: Tenancy — the current agency and the global scope

**Files:**
- Create: `app/Tenancy/Tenant.php`, `app/Tenancy/TenantNotResolved.php`, `app/Models/Scopes/AgencyScope.php`, `app/Models/Concerns/BelongsToAgency.php`, `app/Http/Middleware/SetTenant.php`, `app/Http/Middleware/EnsurePlatform.php`, `app/Http/Resources/AgencyResource.php`, `resources/js/pages/dashboard.tsx` (placeholder, real content in Task 10), `tests/Feature/Tenancy/TenantTest.php`, `tests/Feature/Tenancy/AgencyScopeTest.php`, `tests/Feature/Http/Middleware/SetTenantTest.php`
- Modify: `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `routes/web.php`, `app/Models/User.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `tests/TestCase.php`

**Interfaces:**
- Produces: `Tenant::set(Agency)`, `forget()`, `agency(): ?Agency`, `id(): ?string`, `check(): bool`, `platformId(): string`; `TenantNotResolved` (RuntimeException); trait `BelongsToAgency` (global `AgencyScope` + `agency_id` fill on creating + `agency()` relation) for every tenant model **except `User` and `Agency`**; `User::resolveRouteBindingQuery()` scoped to the tenant; `User::isPlatform()` compares `agency_id` to `Tenant::platformId()`; middleware alias `platform`; `SetTenant` placed before `SubstituteBindings` in the priority list; shared props `agency` and `agencies`; route `dashboard` (closure until Task 10); `TestCase::withTenant(Agency): static`.

Why `User` has no scope (verified against the installed framework): `EloquentUserProvider`, `SessionGuard`, the password broker and Sanctum's `tokenable` all query through `newQuery()`, which applies global scopes; a scoped `User` would make login impossible under a fail-closed scope, and would make a platform user working inside agency X disappear from every `User` query. Users are listed through `$tenant->agency()->users()` instead.

- [ ] **Step 1: Failing tests**

`tests/Feature/Tenancy/TenantTest.php`:

```php
public function test_set_and_forget(): void
{
    $agency = Agency::factory()->create();
    $tenant = app(Tenant::class);

    $this->assertFalse($tenant->check());
    $tenant->set($agency);
    $this->assertTrue($tenant->check());
    $this->assertSame($agency->id, $tenant->id());
    $tenant->forget();
    $this->assertFalse($tenant->check());
}

public function test_tenant_restores_from_context_for_jobs(): void
{
    $agency = Agency::factory()->create();
    app(Tenant::class)->set($agency);
    $this->assertSame($agency->id, Context::getHidden('agency'));

    $this->app->forgetScopedInstances();
    $this->assertSame($agency->id, app(Tenant::class)->id());
}

public function test_platform_id_is_the_platform_row(): void
{
    $this->assertSame(Agency::platform()->id, app(Tenant::class)->platformId());
}
```

`tests/Feature/Tenancy/AgencyScopeTest.php` uses a throwaway tenant model, because the first real one arrives in Milestone 2:

```php
/** Throwaway tenant model for the trait tests. */
#[Table('probes')]
#[Unguarded]
class Probe extends Model
{
    use BelongsToAgency, HasUlids;

    public $timestamps = false;
}

class AgencyScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Touch the app connection first so LazilyRefreshDatabase has migrated,
        // then create the table as the owner, outside the test transaction: the
        // app role inherits CRUD through the default privileges and the next
        // migrate:fresh drops it again. No FK, so no lock on agencies.
        $this->platform();
        DB::connection('owner')->statement('create table if not exists probes (id char(26) primary key, agency_id char(26) not null, name text not null)');
    }

    public function test_reads_are_limited_to_the_current_agency(): void
    {
        [$a, $b] = Agency::factory()->count(2)->create();
        Probe::create(['agency_id' => $a->id, 'name' => 'a1']);
        Probe::create(['agency_id' => $a->id, 'name' => 'a2']);
        Probe::create(['agency_id' => $b->id, 'name' => 'b1']);

        $this->withTenant($a);
        $this->assertSame(['a1', 'a2'], Probe::orderBy('name')->pluck('name')->all());

        $this->withTenant($b);
        $this->assertSame(['b1'], Probe::pluck('name')->all());
    }

    public function test_creating_fills_agency_id_from_the_tenant(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);

        $this->assertSame($agency->id, Probe::create(['name' => 'x'])->agency_id);
    }

    public function test_reading_without_a_tenant_throws(): void
    {
        $this->expectException(TenantNotResolved::class);

        Probe::count();
    }

    public function test_unscoped_reads_need_an_explicit_escape_hatch(): void
    {
        Probe::create(['agency_id' => Agency::factory()->create()->id, 'name' => 'x']);

        $this->assertGreaterThanOrEqual(1, Probe::withoutGlobalScope(AgencyScope::class)->count());
    }
}
```

`tests/TestCase.php` gains `withTenant(Agency $agency): static { app(Tenant::class)->set($agency); return $this; }`.

`tests/Feature/Http/Middleware/SetTenantTest.php`:

```php
public function test_agency_user_gets_their_own_agency(): void
{
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('agency.id', $user->agency_id));
}

public function test_platform_user_defaults_to_the_platform_agency(): void
{
    $this->actingAs(User::factory()->platform()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('agency.platform', true)->has('agencies'));
}

public function test_platform_user_enters_the_agency_held_in_session(): void
{
    $agency = Agency::factory()->create();

    $this->actingAs(User::factory()->platform()->create())->withSession(['agency' => $agency->id])->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('agency.id', $agency->id));
}

public function test_agency_user_ignores_a_session_agency(): void
{
    $user = User::factory()->create();
    $other = Agency::factory()->create();

    $this->actingAs($user)->withSession(['agency' => $other->id])->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('agency.id', $user->agency_id));
}
```

Run → FAIL (`App\Tenancy\Tenant` missing).

- [ ] **Step 2: Tenant service**

```php
<?php

namespace App\Tenancy;

use App\Models\Agency;
use App\Models\Scopes\NotPlatformScope;
use Illuminate\Support\Facades\Context;

/**
 * The agency the current request or job works inside. Bound `scoped`, so
 * Octane and the queue worker get a fresh instance per request or job; the id
 * is mirrored into hidden Context so a queued job restores it.
 */
final class Tenant
{
    private ?Agency $agency = null;

    private bool $resolved = false;

    private ?string $platformId = null;

    public function set(Agency $agency): void
    {
        $this->agency = $agency;
        $this->resolved = true;
        Context::addHidden('agency', $agency->getKey());
    }

    public function forget(): void
    {
        $this->agency = null;
        $this->resolved = true;
        Context::forgetHidden('agency');
    }

    public function agency(): ?Agency
    {
        if (! $this->resolved) {
            $this->resolved = true;
            $id = Context::getHidden('agency');
            $this->agency = $id ? Agency::withoutGlobalScope(NotPlatformScope::class)->find($id) : null;
        }

        return $this->agency;
    }

    public function id(): ?string
    {
        return $this->agency()?->getKey();
    }

    public function check(): bool
    {
        return $this->agency() !== null;
    }

    public function platformId(): string
    {
        return $this->platformId ??= Agency::platform()->getKey();
    }
}
```

`AppServiceProvider::register()`: `$this->app->scoped(Tenant::class);`

- [ ] **Step 3: Scope and trait**

`AgencyScope` fails closed; a silent empty result would hide a misconfiguration, so it throws:

```php
final class AgencyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(Tenant::class);   // resolved here, never in a constructor: Octane recreates it per request

        if ($tenant->check()) {
            $builder->where($model->qualifyColumn('agency_id'), $tenant->id());

            return;
        }

        // Real console runs (seeders, maintenance commands) iterate agencies and
        // call Tenant::set() themselves; HTTP and tests must already have one.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        throw new TenantNotResolved($model::class);
    }
}
```

`TenantNotResolved extends RuntimeException` with the message `"No current agency while querying {$model}: SetTenant did not run, or a command forgot Tenant::set()."` Queue jobs restore the tenant from Context; a job that must span agencies uses `withoutGlobalScope(AgencyScope::class)` and says why.

`BelongsToAgency`:

```php
trait BelongsToAgency
{
    public static function bootBelongsToAgency(): void
    {
        static::addGlobalScope(new AgencyScope);

        static::creating(function (Model $model): void {
            $model->agency_id ??= app(Tenant::class)->id();
        });
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class)->withoutGlobalScope(NotPlatformScope::class);
    }
}
```

`User` keeps its explicit `agency()` relation and gets no global scope. Two changes:

```php
public function isPlatform(): bool
{
    return $this->agency_id === app(Tenant::class)->platformId();
}

/**
 * `{user}` bindings never cross agencies even without a global scope. Guest
 * routes (invite, verification) run with no tenant and keep the plain lookup;
 * their URLs are signed.
 */
public function resolveRouteBindingQuery($query, $value, $field = null)
{
    $tenant = app(Tenant::class);
    $query = parent::resolveRouteBindingQuery($query, $value, $field);

    return $tenant->check() ? $query->where('agency_id', $tenant->id()) : $query;
}
```

- [ ] **Step 4: Middleware and shared props**

`SetTenant`:

```php
final class SetTenant
{
    public function __construct(private Tenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $agency = $user->isPlatform() ? $this->chosen($request) : $user->agency;

            if ($agency) {
                $this->tenant->set($agency);
            }
        }

        return $next($request);
    }

    private function chosen(Request $request): Agency
    {
        $id = $request->session()->get('agency');

        return ($id ? Agency::find($id) : null) ?? Agency::platform();
    }
}
```

`EnsurePlatform`: `abort_unless($request->user()?->isPlatform(), 403); return $next($request);`

`bootstrap/app.php`:

```php
$middleware->web(append: [SetTenant::class, HandleInertiaRequests::class]);
// Appended middleware would run after SubstituteBindings, so route models would
// resolve before the tenant exists. The priority list puts SetTenant right
// before SubstituteBindings, after StartSession and Authenticate.
$middleware->prependToPriorityList(SubstituteBindings::class, SetTenant::class);
$middleware->alias(['platform' => EnsurePlatform::class]);
```

`routes/web.php`: add `Route::middleware(['auth', 'verified'])->group(fn () => Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard'));` and a placeholder `resources/js/pages/dashboard.tsx` rendering `AppLayout` with the agency name (Task 10 replaces both).

`HandleInertiaRequests` (inject `Tenant`): add `'agency' => fn () => ($agency = $this->tenant->agency()) ? AgencyResource::make($agency)->resolve() : null` and `'agencies' => fn () => $request->user()?->isPlatform() ? AgencyResource::collection(Agency::orderBy('name')->get())->resolve() : []`. `AgencyResource`: `id, code, name, platform`.

- [ ] **Step 5: Run everything, record the rule, commit**

```bash
php artisan test --compact && vendor/bin/pint --dirty --format agent
```

Boost `record-rule` glob `app/Models/**`, title "Tenant models use BelongsToAgency", note: "Every model with agency_id except User and Agency uses App\Models\Concerns\BelongsToAgency (global AgencyScope keyed to App\Tenancy\Tenant, fail-closed outside real console runs, agency_id filled on creating). User has no scope: list users through the tenant's agency relation; {user} bindings are scoped by resolveRouteBindingQuery. To cross tenants use withoutGlobalScope(AgencyScope::class) and say why. Holidays get their own scope (own + platform). Agency hides the platform row; reach it with Agency::platform(). SetTenant must stay in the middleware priority list before SubstituteBindings."

```bash
git add -A && git commit -m "feat: tenant service, agency scope and middleware"
```

### Task 7: Session authentication, password reset, verification, invites

**Files:**
- Create: `routes/auth.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `PasswordResetLinkController.php`, `NewPasswordController.php`, `EmailVerificationPromptController.php`, `VerifyEmailController.php`, `EmailVerificationNotificationController.php`, `InviteController.php`, `app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Requests/Auth/AcceptInviteRequest.php`, `app/Notifications/InviteNotification.php`, `app/Actions/InviteUser.php`, `resources/js/pages/auth/login.tsx`, `forgot-password.tsx`, `reset-password.tsx`, `verify-email.tsx`, `accept-invite.tsx`, `tests/Feature/Http/Controllers/Auth/*Test.php`, `tests/Feature/Actions/InviteUserTest.php`
- Modify: `routes/web.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php` (rate limiter), `app/Http/Middleware/HandleInertiaRequests.php`

**Interfaces:**
- Produces: routes `login`, `logout`, `password.request`, `password.email`, `password.reset`, `password.store`, `verification.notice`, `verification.verify`, `verification.send`, `invite.accept` (GET, signed), `invite.store` (POST, signed); `InviteUser::handle(array{name: string, email: string, permissions: array<string>}): User` sends `InviteNotification`; shared props `auth.user`, `flash`.

- [ ] **Step 1: Failing tests** (one class per controller; representative cases)

`tests/Feature/Http/Controllers/Auth/AuthenticatedSessionControllerTest.php`:

```php
public function test_login_page_renders(): void
{
    $this->get(route('login'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/login'));
}

public function test_valid_credentials_start_a_session_and_redirect_to_dashboard(): void
{
    $user = User::factory()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
}

public function test_wrong_password_returns_the_generic_error(): void
{
    $user = User::factory()->create();

    $this->from(route('login'))->post(route('login'), ['email' => $user->email, 'password' => 'nope'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
    $this->assertGuest();
}

public function test_uninvited_or_unaccepted_users_cannot_log_in(): void
{
    $user = User::factory()->invited()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
}

public function test_sixth_attempt_in_a_minute_is_throttled(): void
{
    $user = User::factory()->create();
    foreach (range(1, 5) as $i) {
        $this->post(route('login'), ['email' => $user->email, 'password' => 'nope']);
    }

    $this->post(route('login'), ['email' => $user->email, 'password' => 'nope'])->assertStatus(429);
}

public function test_logout_ends_the_session(): void
{
    $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect('/');
    $this->assertGuest();
}
```

`PasswordResetTest`: request link sends `ResetPassword` notification (`Notification::fake([ResetPassword::class])`), reset page renders with token, valid token updates the password (`Hash::check`), invalid token errors. `EmailVerificationTest`: unverified user is redirected to `verification.notice` from `dashboard`; signed verify URL sets `email_verified_at` and redirects to dashboard; resend is throttled. `InviteControllerTest`: signed link renders `auth/accept-invite` with the user's name/email; expired signature returns 403; posting a password marks `email_verified_at`, logs the user in, redirects to dashboard; an already-accepted user is redirected to login with an error; unsigned URL is 403. `tests/Feature/Actions/InviteUserTest.php`: creates the user in the current tenant with `invited_at`, random password, given permissions, and sends `InviteNotification` once with a URL that passes `URL::hasValidSignature`.

Run: `php artisan test --compact tests/Feature/Http/Controllers/Auth` → FAIL (routes missing).

- [ ] **Step 2: Routes**

`routes/auth.php`:

```php
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
    Route::get('invite/{user}', [InviteController::class, 'create'])->middleware('signed')->name('invite.accept');
    Route::post('invite/{user}', [InviteController::class, 'store'])->middleware('signed')->name('invite.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])->middleware('throttle:6,1')->name('verification.send');
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
```

`routes/web.php` keeps the welcome route and the `dashboard` closure route from Task 6 (Task 10 swaps in the controller) and adds `require __DIR__.'/auth.php';`.

`AppServiceProvider::boot()` → `configureRateLimiting()`:

```php
RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(Str::lower($request->string('email')).'|'.$request->ip()));
```

- [ ] **Step 3: Login request and controllers**

`LoginRequest` (`php artisan make:request Auth/LoginRequest --no-interaction`): rules `email: required|string|email`, `password: required|string`; method `authenticate(): void`:

```php
public function authenticate(): void
{
    $credentials = $this->only('email', 'password');

    if (! Auth::attempt($credentials, $this->boolean('remember'))) {
        throw ValidationException::withMessages(['email' => __('auth.failed')]);
    }

    if (Auth::user()->invited_at !== null && Auth::user()->email_verified_at === null) {
        Auth::logout();
        throw ValidationException::withMessages(['email' => 'This invitation has not been accepted yet. Use the link in your email.']);
    }
}
```

`AuthenticatedSessionController`: `create()` → `Inertia::render('auth/login', ['canResetPassword' => Route::has('password.request'), 'status' => session('status')])`; `store(LoginRequest $request)` → `$request->authenticate(); $request->session()->regenerate(); return redirect()->intended(route('dashboard'));`; `destroy()` → `Auth::guard('web')->logout(); session()->invalidate(); session()->regenerateToken(); return redirect('/');`.

`PasswordResetLinkController`, `NewPasswordController`, `EmailVerificationPromptController`, `VerifyEmailController`, `EmailVerificationNotificationController`: the Breeze shapes on the `Password` broker and `EmailVerificationRequest`; every write validates through a Form Request or inline `validate()` (email, token, `Password::defaults()` confirmed). Pages: `auth/forgot-password`, `auth/reset-password` (props `email`, `token`), `auth/verify-email` (prop `status`).

`InviteController::create(Request $request, User $user)`: if `$user->email_verified_at` → `redirect()->route('login')->with('error', 'This invitation was already accepted. Sign in instead.')`; else `Inertia::render('auth/accept-invite', ['user' => ['name' => $user->name, 'email' => $user->email], 'action' => $request->fullUrl()])` (the signed URL is reused as the POST action). `store(AcceptInviteRequest $request, User $user)`: rules `password: required, confirmed, Password::defaults()`; `$user->forceFill(['password' => $request->password, 'email_verified_at' => now()])->save(); Auth::login($user); session()->regenerate(); return redirect()->route('dashboard')->with('success', 'Welcome, '.$user->name.'.');`.

- [ ] **Step 4: Invite action and notification**

`app/Actions/InviteUser.php` (`php artisan make:class Actions/InviteUser --no-interaction`; `Tenant` comes from Task 6):

```php
final class InviteUser
{
    public function __construct(private Tenant $tenant) {}

    /** @param array{name: string, email: string, permissions: array<int, string>} $attributes */
    public function handle(array $attributes): User
    {
        $user = User::create([
            ...$attributes,
            'agency_id' => $this->tenant->id(),
            'password' => Str::password(32),
            'invited_at' => now(),
        ]);

        $user->notify(new InviteNotification);

        return $user;
    }
}
```

`InviteNotification` (`php artisan make:notification InviteNotification --no-interaction`, `ShouldQueue`): `toMail` → subject "You're invited to khronoz", line "{agency name} set up your account.", action "Accept invitation" → `URL::temporarySignedRoute('invite.accept', now()->addDays(7), ['user' => $notifiable])`, line "This link works for 7 days." **YOUR CALL** (decision 2): lifetime and re-send behaviour; default 7 days, re-send from the users list regenerates the link (Task 9).

- [ ] **Step 5: Shared props**

`HandleInertiaRequests::share()`:

```php
return [
    ...parent::share($request),
    'auth' => ['user' => $request->user() ? UserResource::make($request->user())->resolve() : null],
    'flash' => fn () => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error')],
];
```

`UserResource` (`php artisan make:resource UserResource --no-interaction`): `id, name, email, permissions (values), platform => $this->isPlatform(), employee_id, invited_at, email_verified_at`. Task 6 already shares `agency` and `agencies`.

- [ ] **Step 6: Pages**

`resources/js/pages/auth/login.tsx` (pattern for the other auth pages: `AuthLayout`, Inertia `Form`, shadcn inputs, `InputError`):

```tsx
import { Form, Link } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { request } from '@/routes/password';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function Login({ status, canResetPassword }: { status?: string; canResetPassword: boolean }) {
    return (
        <AuthLayout title="Sign in" description="Use the email your HR office invited.">
            {status && <p className="text-sm text-primary">{status}</p>}
            <Form {...store.form()} resetOnSuccess={['password']} className="grid gap-4">
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" name="email" type="email" autoComplete="email" autoFocus required />
                            <InputError message={errors.email} />
                        </div>
                        <div className="grid gap-2">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="password">Password</Label>
                                {canResetPassword && <Link href={request()} className="text-sm text-muted-foreground hover:text-foreground">Forgot password?</Link>}
                            </div>
                            <Input id="password" name="password" type="password" autoComplete="current-password" required />
                            <InputError message={errors.password} />
                        </div>
                        <label className="flex items-center gap-2 text-sm"><Checkbox name="remember" /> Keep me signed in</label>
                        <Button type="submit" disabled={processing}>Sign in</Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
```

`forgot-password.tsx` (email field, "Send reset link"), `reset-password.tsx` (hidden token + email, password, confirmation, "Set new password"), `verify-email.tsx` (text + "Resend email" form + logout form), `accept-invite.tsx` (greets by name, shows email read-only, password + confirmation, "Accept invitation"; the form posts to the `action` prop because the URL carries the signature).

- [ ] **Step 7: Run, lint, commit**

```bash
php artisan wayfinder:generate --with-form && npm run lint && npm run types
php artisan test --compact
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: session auth, password reset, verification and invitations"
```

### Task 8: Platform area — agencies and entering one

**Files:**
- Create: `app/Http/Controllers/Platform/AgencyController.php`, `app/Http/Controllers/Platform/EnterAgencyController.php`, `app/Http/Requests/Platform/StoreAgencyRequest.php`, `UpdateAgencyRequest.php`, `app/Actions/CreateAgency.php`, `app/Policies/AgencyPolicy.php`, `resources/js/pages/platform/agencies/index.tsx`, `create.tsx`, `edit.tsx`, `resources/js/components/agency-switcher.tsx`, `tests/Feature/Http/Controllers/Platform/AgencyControllerTest.php`, `EnterAgencyControllerTest.php`
- Modify: `routes/web.php`, `resources/js/components/app-sidebar.tsx`

**Interfaces:**
- Produces: routes `platform.agencies.index|create|store|edit|update`, `platform.agencies.enter` (POST `platform/agencies/{agency}/enter`), `platform.agencies.leave` (DELETE `platform/enter`); `CreateAgency::handle(array{code: string, name: string}): Agency` (Milestone 3 adds the defaults copy here); session key `agency`.

- [ ] **Step 1: Failing tests**

```php
public function test_agency_users_cannot_reach_the_platform_area(): void
{
    $this->actingAs(User::factory()->preset(Preset::Admin)->create())->get(route('platform.agencies.index'))->assertForbidden();
}

public function test_platform_user_lists_agencies_without_the_platform_row(): void
{
    Agency::factory()->count(3)->create();

    $this->actingAs(User::factory()->platform()->create())->get(route('platform.agencies.index'))
        ->assertInertia(fn (Assert $page) => $page->component('platform/agencies/index')->has('agencies', 3));
}

public function test_store_creates_an_agency_and_redirects(): void
{
    $this->actingAs(User::factory()->platform()->create())
        ->post(route('platform.agencies.store'), ['code' => 'DOH', 'name' => 'Department of Health'])
        ->assertRedirect(route('platform.agencies.index'))->assertSessionHas('success');

    $this->assertDatabaseHas('agencies', ['code' => 'DOH', 'platform' => false]);
}

public function test_store_requires_a_unique_code(): void
{
    Agency::factory()->create(['code' => 'DOH']);

    $this->actingAs(User::factory()->platform()->create())
        ->post(route('platform.agencies.store'), ['code' => 'doh', 'name' => 'Dup'])
        ->assertSessionHasErrors(['code' => 'The code has already been taken.']);
}

public function test_enter_stores_the_agency_in_the_session_and_leave_clears_it(): void
{
    $agency = Agency::factory()->create();
    $superuser = User::factory()->platform()->create();

    $this->actingAs($superuser)->post(route('platform.agencies.enter', $agency))
        ->assertRedirect(route('dashboard'))->assertSessionHas('agency', $agency->id);

    $this->actingAs($superuser)->delete(route('platform.agencies.leave'))
        ->assertRedirect(route('dashboard'))->assertSessionMissing('agency');
}

public function test_entering_the_platform_row_is_not_possible_by_id(): void
{
    $this->actingAs(User::factory()->platform()->create())
        ->post(route('platform.agencies.enter', Agency::platform()->id))->assertNotFound();
}
```

- [ ] **Step 2: Routes, policy, requests, action, controllers**

`routes/web.php` inside the `auth, verified` group:

```php
Route::middleware('platform')->prefix('platform')->name('platform.')->group(function () {
    Route::resource('agencies', AgencyController::class)->except(['show', 'destroy']);
    Route::post('agencies/{agency}/enter', [EnterAgencyController::class, 'store'])->name('agencies.enter');
    Route::delete('enter', [EnterAgencyController::class, 'destroy'])->name('agencies.leave');
});
```

`AgencyPolicy` (`php artisan make:policy AgencyPolicy --model=Agency --no-interaction`): every ability returns `$user->isPlatform()` (Gate::before already answers true; the policy documents intent and protects the resource if the middleware moves).

`StoreAgencyRequest`: `code: required, string, max:16, alpha_dash, Rule::unique('agencies', 'code')` with `prepareForValidation` upper-casing `code`; `name: required, string, max:120`. `UpdateAgencyRequest`: same with `->ignore($this->route('agency'))`.

`CreateAgency::handle(array $attributes): Agency` → `Agency::create($attributes)` (Milestone 3 wraps this in a transaction that copies platform shifts and schedules).

`AgencyController`: `index` → `Inertia::render('platform/agencies/index', ['agencies' => AgencyResource::collection(Agency::orderBy('name')->get())->resolve()])`; `create`; `store(StoreAgencyRequest $request, CreateAgency $create)` → redirect index with `success` "Agency {code} created."; `edit`; `update` → redirect index with `success`.

`EnterAgencyController::store(Agency $agency)` → `session(['agency' => $agency->id])`, redirect dashboard with `success` "You are now working in {name}."; `destroy()` → `session()->forget('agency')`, redirect dashboard.

- [ ] **Step 3: Pages and the switcher**

`platform/agencies/index.tsx`: `AppLayout` breadcrumbs `[Platform, Agencies]`, `PageHeader` title "Agencies" with action `<Button asChild><Link href={create()}>Add agency</Link></Button>`, shadcn `Table` with columns Code, Name, and a row action `Form` posting `enter(agency)` labelled "Enter"; `EmptyState` "No agencies yet" with the add action. `create.tsx`/`edit.tsx`: `Form` with `code` (upper-case, monospace not used; hint "Short and unique, e.g. DOH") and `name`, buttons "Create agency" / "Save changes".

`agency-switcher.tsx` (rendered in the sidebar header only for platform users): shadcn `DropdownMenu` trigger showing the current `agency.name` with `ChevronsUpDown`; items: "Platform" (posts `leave()` when a tenant is entered), a divider, one item per `agencies` entry (posts `enter(agency)`), and "Manage agencies" linking to `index()`. Non-platform users see a static agency name.

- [ ] **Step 4: Run, lint, commit**

```bash
php artisan wayfinder:generate --with-form && npm run lint && npm run types && php artisan test --compact
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: platform agencies and entering an agency"
```

### Task 9: Users management inside an agency

**Files:**
- Create: `app/Http/Controllers/UserController.php`, `app/Http/Controllers/UserInviteController.php`, `app/Http/Requests/StoreUserRequest.php`, `UpdateUserRequest.php`, `app/Policies/UserPolicy.php`, `resources/js/pages/users/index.tsx`, `create.tsx`, `edit.tsx`, `resources/js/components/permission-picker.tsx`, `tests/Feature/Http/Controllers/UserControllerTest.php`, `tests/Feature/Policies/UserPolicyTest.php`
- Modify: `routes/web.php`, `app/Http/Resources/UserResource.php`, `app/Actions/InviteUser.php` (accept an optional `Preset`)

**Interfaces:**
- Produces: routes `users.index|create|store|edit|update|destroy`, `users.invite` (POST `users/{user}/invite`, re-send); `PermissionPicker({ value, onChange, presets })` React component; `UserResource` adds `permissions` grouped for display.

- [ ] **Step 1: Failing tests**

```php
#[DataProvider('permissionsWithoutUsersManage')]
public function test_users_without_users_manage_are_forbidden(Permission $permission): void
{
    $this->actingAs(User::factory()->permissions($permission)->create())->get(route('users.index'))->assertForbidden();
}

public static function permissionsWithoutUsersManage(): array
{
    return collect(Permission::cases())->reject(fn (Permission $p) => $p === Permission::ManageUsers)
        ->mapWithKeys(fn (Permission $p) => [$p->value => [$p]])->all();
}

public function test_index_lists_only_the_current_agency(): void
{
    $admin = User::factory()->permissions(Permission::ManageUsers)->create();
    User::factory()->forAgency($admin->agency)->count(2)->create();
    User::factory()->count(3)->create(); // other agencies

    $this->actingAs($admin)->get(route('users.index'))
        ->assertInertia(fn (Assert $page) => $page->component('users/index')->has('users', 3));
}

public function test_store_invites_a_user_with_the_chosen_permissions(): void
{
    Notification::fake([InviteNotification::class]);
    $admin = User::factory()->permissions(Permission::ManageUsers)->create();

    $this->actingAs($admin)->post(route('users.store'), ['name' => 'Ana Cruz', 'email' => 'ana@x.test', 'permissions' => ['organization.manage', 'scheduling.view']])
        ->assertRedirect(route('users.index'));

    $user = User::withoutGlobalScopes()->where('email', 'ana@x.test')->firstOrFail();
    $this->assertSame($admin->agency_id, $user->agency_id);
    $this->assertNotNull($user->invited_at);
    $this->assertEqualsCanonicalizing(['organization.manage', 'scheduling.view'], $user->permissions->map->value->all());
    Notification::assertSentTo($user, InviteNotification::class);
}

public function test_store_rejects_unknown_permissions(): void
{
    $this->actingAs(User::factory()->permissions(Permission::ManageUsers)->create())
        ->post(route('users.store'), ['name' => 'x', 'email' => 'x@x.test', 'permissions' => ['root']])
        ->assertSessionHasErrors('permissions.0');
}

public function test_editing_a_user_of_another_agency_is_not_found(): void
{
    $stranger = User::factory()->create();

    $this->actingAs(User::factory()->permissions(Permission::ManageUsers)->create())
        ->get(route('users.edit', $stranger))->assertNotFound();
}

/** Guards the middleware order: a binding resolved before SetTenant would 404 here. */
public function test_editing_a_colleague_renders(): void
{
    $admin = User::factory()->permissions(Permission::ManageUsers)->create();
    $colleague = User::factory()->forAgency($admin->agency)->create();

    $this->actingAs($admin)->get(route('users.edit', $colleague))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('users/edit')->where('user.id', $colleague->id));
}

public function test_admins_cannot_remove_themselves(): void
{
    $admin = User::factory()->permissions(Permission::ManageUsers)->create();

    $this->actingAs($admin)->delete(route('users.destroy', $admin))->assertForbidden();
}
```

`UserPolicyTest`: `viewAny/create/update/delete` true only with `ManageUsers`; `delete` false for self; platform user true everywhere (via `Gate::before`, asserted through `Gate::forUser`).

- [ ] **Step 2: Routes, policy, requests, controllers**

Routes inside `auth, verified`: `Route::resource('users', UserController::class)->except(['show']); Route::post('users/{user}/invite', [UserInviteController::class, 'store'])->name('users.invite');`

`UserPolicy`: `viewAny`, `create`, `update` → `$user->allows(Permission::ManageUsers)`; `delete(User $user, User $target)` → same and `! $user->is($target)`.

`StoreUserRequest`: `authorize()` → `$this->user()->can('create', User::class)`; rules `name: required, string, max:120`; `email: required, email, max:254, Rule::unique('users', 'email')->where(fn ($q) => $q->whereRaw('lower(email) = ?', [Str::lower($this->string('email'))]))` (the DB index is on `lower(email)`); `permissions: array`, `permissions.*: [Rule::enum(Permission::class)]`. `UpdateUserRequest`: `name`, `permissions` (email not editable in v1).

`UserController` (constructor-injects `Tenant`): `index` (`$this->tenant->agency()->users()->orderBy('name')`, never a bare `User::query()`, because `User` carries no tenant scope; `UserResource` collection; `filters.search` on name/email), `create` (`presets` prop: `[{value, label, permissions}]`, `permissions` prop: `[{value, label, group}]`), `store(StoreUserRequest, InviteUser)`, `edit`, `update`, `destroy` (`Gate::authorize('delete', $user)`; refuses with 403 for self; deletes; Milestone 7 blocks deletion of users who signed attestations through the FK). `UserInviteController::store(User $user)` → `Gate::authorize('update', $user)`; if already accepted → back with error; else `$user->notify(new InviteNotification)` and back with success "Invitation sent again to {email}."

- [ ] **Step 3: Pages and the permission picker**

`permission-picker.tsx`: a `ToggleGroup` of presets (Admin / HR / Viewer / Custom) that sets the checked set, then permissions grouped by `group` with a `Checkbox` per permission and its `label`; checking a `manage` permission also checks and disables its implied `view`. Emits hidden inputs `permissions[]` for the Inertia `Form`.

`users/index.tsx`: `PageHeader` "Users" + "Invite user"; `Table` columns Name (with email under it), Access (badges: "Admin" when all, else count "5 permissions" with a `Tooltip` listing them), Status (`Badge` "Invited" when `invited_at && !email_verified_at`, else "Active"), row actions `DropdownMenu`: Edit, Resend invitation (when invited), Remove (`AlertDialog` "Remove {name}? They lose access immediately."). `create.tsx`: name, email, `PermissionPicker`, "Send invitation". `edit.tsx`: name, `PermissionPicker`, "Save changes".

- [ ] **Step 4: Run, lint, commit**

```bash
php artisan wayfinder:generate --with-form && npm run lint && npm run types && php artisan test --compact
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: invite and manage users with permissions"
```

### Task 10: Dashboard and the operational dashboards gate

**Files:**
- Create: `app/Http/Controllers/DashboardController.php`, `resources/js/pages/dashboard.tsx`, `tests/Feature/Http/Controllers/DashboardControllerTest.php`, `tests/Feature/Support/DashboardTest.php`
- Modify: `app/Support/Dashboard.php`, `routes/web.php`, `resources/js/pages/welcome.tsx`

**Interfaces:**
- Produces: `GET /dashboard` (`dashboard`) rendering `dashboard` with `counts: { users: number }` and, for platform tenants, `counts.agencies`; `Dashboard::allows(?Authenticatable $user): bool` true only for platform users outside local.

- [ ] **Step 1: Failing tests**

```php
public function test_guests_are_redirected_to_login(): void
{
    $this->get(route('dashboard'))->assertRedirect(route('login'));
}

public function test_dashboard_shows_the_current_agency_counts(): void
{
    $user = User::factory()->create();
    User::factory()->forAgency($user->agency)->count(2)->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->component('dashboard')->where('counts.users', 3));
}

public function test_horizon_gate_admits_platform_users_only(): void
{
    $this->app->detectEnvironment(fn () => 'production');

    $this->assertTrue(Dashboard::allows(User::factory()->platform()->create()));
    $this->assertFalse(Dashboard::allows(User::factory()->preset(Preset::Admin)->create()));
    $this->assertFalse(Dashboard::allows(null));
}
```

- [ ] **Step 2: Implement**

`Dashboard::allows()` → `app()->environment('local') || ($user instanceof User && $user->isPlatform())`; remove `config/dashboard.php` and `DASHBOARD_EMAILS` from `.env.example` (the TODO is fulfilled). `DashboardController` (invokable, injects `Tenant`) → `counts.users` through `$tenant->agency()->users()->count()` and, when `$tenant->agency()->platform`, `counts.agencies` through `Agency::count()`. `dashboard.tsx`: `AppLayout`, `PageHeader` with the agency name, a two-column definition list of counts (no stat cards), and for platform tenants a link "Manage agencies". `welcome.tsx`: replace the package list with a one-line description and a "Sign in" link to `login()`; keep SSR metadata.

- [ ] **Step 3: Run, lint, commit**

```bash
php artisan test --compact && npm run lint && npm run types && vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: dashboard and platform-only operational dashboards"
```

### Task 11: Record conventions, seed the local environment, close the milestone

**Files:**
- Create: `.ai/rules/index.md` and rule files via Boost `record-rule`
- Modify: `README.md` (setup section), `docs/superpowers/plans/2026-09-09-khronoz-m1-foundation.md` (this plan, saved at execution start)

- [ ] **Step 1: Record the remaining rules** with `record-rule`: (a) glob `tests/**` — "Constraint tests use assertDatabaseRefuses(sqlstate, fn) through the app connection; one test per constraint and trigger in docs/design/07-constraints.md"; (b) glob `resources/js/**` — "Pages under pages/<resource>/<action>.tsx, Inertia <Form> with Wayfinder actions, shadcn primitives from components/ui only, tokens in app.css, Public Sans, tabular-nums on times"; (c) glob `app/Http/Controllers/**` — "Controllers: Form Request → action or model → redirect()->route(...)->with('success'|'error'); Inertia props through JsonResource::resolve(); cross-tenant requests must 404 (route binding under the scope)".

- [ ] **Step 2: README setup section**

Document: `docker compose up -d`, `composer setup` (update the script to call `composer migrate`), `php artisan db:seed`, `php artisan dev`, URLs (app 43080, Mailpit 43825, Horizon `/horizon`), dev superuser credentials, `php artisan test --compact`.

- [ ] **Step 3: Full verification, commit**

```bash
php artisan migrate:fresh --database=owner --seed --force
php artisan test --compact && npm run lint && npm run types && npm run build && vendor/bin/pint --format agent
git add -A && git commit -m "chore: record conventions and setup notes for milestone 1"
```

## Milestone 1 verification (end to end)

1. `docker ps` shows the four `khronoz-*` containers healthy; `composer migrate` runs as `sail`; `php artisan tinker --execute 'echo DB::selectOne("select current_user as u")->u;'` prints `khronoz_app`.
2. `php artisan test --compact`: green, including every `assertDatabaseRefuses` test (`23505` second platform row, `P0001` platform delete/flag change, `23514` settings/permissions shape, `42501` app role DDL).
3. Start `php artisan dev`; open `http://localhost:43080` in the Browser pane. Sign in as `superuser@khronoz.test` / `password` → dashboard shows the platform tenant and the agencies count.
4. Platform → Agencies → Add agency `DOH` → Enter → sidebar switcher shows DOH → Users → Invite user with the HR preset.
5. Open Mailpit `http://localhost:43825`, follow "Accept invitation", set a password → lands on the DOH dashboard; the sidebar shows Users only if `users.manage` was granted; `/platform/agencies` returns 403 for this user.
6. Screenshot review against the design brief (frontend-design): Public Sans renders, primary green on buttons, no cards on the dashboard, tables with hairlines, focus rings visible when tabbing, `prefers-color-scheme: dark` shows the dark tokens when `.dark` is applied.
7. `npm run build` produces the SSR bundle; `/` still server-renders (`curl -s localhost:43080 | grep data-server-rendered`).

---

# Roadmap — Milestones 2 to 9

Each milestone below becomes its own writing-plans document before it starts. Listed here: scope, files, tests and exit criteria so the whole shape is visible now.

## Milestone 2 — Organization (design 01, 07)

- **Tables:** `units` (parent, kind, code, name, head_id; paired FKs, `UNIQUE (agency_id, code)`, `CHECK (parent_id IS DISTINCT FROM id)`, trigger `units_acyclic`), `employees` (number, names, sex, birthdate, email, mobile, position, status, exempt, hired_at, separated_at, soft deletes; `UNIQUE (agency_id, number)`, `CHECK separated_at >= hired_at`, trigger `agency_not_platform`), `deployments` (exclusion on `employee_id, daterange(starts, ends, '[]')`), `groups`, `members` (exclusion on group+employee+range). Also `ALTER TABLE users ADD FOREIGN KEY (employee_id, agency_id) REFERENCES employees (id, agency_id)`. Function `agency_not_platform()` reused by units, employees, groups, terminals.
- **Models:** `Unit` (tree helpers: `descendants()` via recursive CTE query builder, `ancestors()`), `Employee` (Scout `Searchable`, `SoftDeletes`, `currentDeployment()`), `Deployment`, `Group`, `Member`. Enums `Sex`, `EmploymentStatus`.
- **Actions:** `MoveEmployee` (ends the open deployment, opens the new one in a transaction), `EnrollMembers`.
- **UI:** employees index as a shadcn data table (`@tanstack/react-table`: search, unit filter, status filter, pagination via Inertia `only`), employee create/edit (two-column form), employee show (profile + deployment history timeline as a table), units page (indented tree with kind labels, head picker as `Command` combobox), groups page with member management (add many via searchable multi-select).
- **Tests:** every constraint and trigger; cross-tenant 404s; policy matrix for `organization.*`; recursive CTE returns the right subtree.
- **Exit:** onboarding shapes A/B/C from 00-principles can be entered without code changes.

## Milestone 3 — Scheduling (design 04, 07) — the core

- **Tables:** `shifts` (`slots jsonb`, `required`, `flex`, `remote`, `trust`, `origin_id`; `slots_valid()` function and the six CHECKs; trigger `origin_is_platform`), `schedules` (`length`, `fallback_shift_id`, `origin_id`), `turns` (`UNIQUE (schedule_id, position)`, deferred constraint trigger `turns_complete`), `rosters` (exclusion per employee range).
- **Domain:** `App\Scheduling\Slots` value object (parse `"HH:MM"` past 24:00 into minutes, validate the same rules as `slots_valid()` for form errors), `Resolver::shift(Employee, CarbonImmutable $date): ?Shift` (`position = (D − anchor) mod length`), `Expectation::for(Shift, date, ?firstIn)` producing expected `in/out` timestamps per slot with `flex` applied. Pure PHP, unit-tested with the seven examples in 04 (Standard, Flexi 08:23 and 10:30, Long/CWW, Hospital night 22:00–30:00 on 8 Sep, 12h, Duty24 `32:00`, 48-hour `56:00`).
- **Platform defaults:** `DefaultsSeeder` creating the tier-2 set on the platform agency (Standard 8–5, seven flexitime options, CWW 7–6 and 8–7, Ramadan, Off, Remote; schedules Standard week, CWW Mon–Thu, CWW Tue–Fri, CWW Wed off, CWW Mon–Thu with remote Friday). `CopyDefaults::handle(Agency)` deep-copies shifts then schedules with turns, setting `origin_id`; called from `CreateAgency`. `RefreshFromOrigin` action and a "differs from platform default" indicator (compare slots/required/flex/remote/trust).
- **Actions:** `AssignSchedule::handle(Employee|Group, Schedule, anchor, starts, ?ends)` (ends the open roster at `starts − 1` inside a transaction; the exclusion constraint is the last word), `EndRoster`.
- **UI (the hero):** shift editor with a visual 24–72h timeline of slots (add pair, drag not required in v1; numeric time inputs with past-24 hint "30:00 = 06:00 next day"), live validation mirroring `slots_valid`; schedule builder: cycle length + a strip of turn cells each picking a shift (chips coloured per shift), fallback shift select; roster grid: employees × days for a month with chips, cross-midnight bars spilling into the next day, team anchors visible, "Assign schedule" sheet for one employee or a group; defaults screen inside an agency listing platform shifts/schedules with "Copy" / "Refresh" per row.
- **Tests:** constraint tests for every CHECK/trigger (half-built schedule refused at commit, overlapping rosters refused, roster pointing at a platform schedule refused by the paired FK), resolver unit tests, copy-on-onboarding test, policy matrix `scheduling.*`.
- **Exit:** onboarding shapes B, C (hospital rotation length 21, three anchors), D (CWW with fallback), E (remote Friday) can be rostered; the roster grid renders a hospital month correctly.

## Milestone 4 — Calendar (design 05, 07)

- **Tables:** `holidays` (`UNIQUE (agency_id, date, name)`, type CHECK, `declared_at`), `suspensions` (unit scope, `starts/ends` time, `declared_at`, user_id), `exemptions` (type CHECK, `starts/ends`, `approved_at`, `UNIQUE (id, employee_id)`), `overtimes` (timestamps, generated `date STORED`, mode CHECK, exclusion per employee `tsrange`).
- **Scopes:** `HolidayScope` (`agency_id IN (tenant, platform)`); platform holidays editable only as the platform tenant.
- **Seeders:** Proclamation 1006 (2026) national holidays on the platform agency, `declared_at` = proclamation date.
- **UI:** calendar month grid (react-day-picker in multiple-months mode is not needed; a custom month table) showing holidays/suspensions; forms for each; exemptions and overtimes entered from the employee page and from a per-day list; suspension form with unit picker ("agency-wide" default).
- **Tests:** constraints, `HolidayScope` shows national + own, unit cascade query (descendants) returns affected employees, policy matrix `calendar.*`.

## Milestone 5 — Terminals and timelogs (design 03, 07)

- **Tables:** `terminals` (kind/protocol CHECKs, `UNIQUE (agency_id, code)`, partial unique `serial`, encrypted `secret`), `enrollments` (two exclusion constraints, `UNIQUE (id, employee_id, terminal_id, uid)`, trigger `enrollments_reresolve`), `syncs` (CHECKs incl. `received = accepted + duplicates + rejected`), `timelogs` (natural key `UNIQUE (terminal_id, uid, time, state, mode)`, `UNIQUE (id, employee_id)`, resolved/unresolved CHECKs, trigger `timelogs_resolve` SECURITY DEFINER, `REVOKE DELETE/UPDATE` + `GRANT UPDATE (voided_at, reason)`; `REVOKE DELETE ON syncs`).
- **Ingestion:** `Ingest::handle(Terminal, iterable<array{uid,time,state,mode}>, Sync): IngestResult` doing `INSERT ... ON CONFLICT DO NOTHING RETURNING id, employee_id, time` in chunks, counting accepted/duplicates/rejected, dispatching `RecomputeWorkday` per `(employee_id, date)` for `T::date − 3 .. T::date` (jobs exist from Milestone 6; here they are dispatched behind an interface). Drivers by protocol: `file` (attlog `.dat` upload, parser unit-tested on fixtures), `push` (ADMS/iClock endpoints `GET /iclock/cdata` handshake, `POST /iclock/cdata` ATTLOG, `GET /iclock/getrequest`; terminal auth by serial + secret; CSRF excluded), `pull` (ZK TCP driver, last, hardware-verified). Manual timelog form (`source = manual`, `user_id`), void with reason.
- **UI:** terminals list with last seen/synced and unresolved count; enrollments per terminal (uid ↔ employee with date ranges); import dialog with a result summary; timelog explorer (filters: employee, terminal, date, unresolved, voided) with day markers; sync history.
- **Tests:** every constraint and privilege (app role cannot delete or update `time`; can void), `timelogs_resolve` picks the covering enrollment, `enrollments_reresolve` re-links and un-links, upsert idempotence, parser fixtures, push protocol feature tests with recorded payloads.

## Milestone 6 — Attendance engine (design 06, 07)

- **Tables:** `ledgers` (`UNIQUE (employee_id, month)`, `UNIQUE (id, employee_id, month)`, month CHECK, triggers `ledgers_lock_complete` and `ledgers_unlock_clean`), `workdays` (generated `month STORED`, three-column ledger FK, shift/exemption paired FKs, `UNIQUE (employee_id, date)`, status CHECK, minutes CHECKs), `punches` (`ON DELETE CASCADE` from workday, timelog paired FK, `UNIQUE (workday_id, slot, kind)`, partial unique `timelog_id`, trigger `punches_timelog_live`).
- **Pipeline (`App\Attendance\`):** `Computer::compute(Employee, date): Workday` = `Resolver` (M3) → `Calendar::apply` (holiday, suspension truncation and §32 charge, exemption window, CWW fallback week) → `Matcher::match(expectations, timelogs, trust, window, grace, missing policy)` → `Deriver` (status, tardy, undertime, worked, excess, night; rules 1–9 of 06). Pure classes with unit tests per rule and the two worked examples (five timelogs on 8 Sep; night shift 30 Sep/1 Oct; 48-hour duty across month end).
- **Jobs:** `RecomputeWorkday` (`ShouldBeUnique` on `employee:date`, checks `ledger.locked_at`, `firstOrCreate` ledger, writes workday + punches in a transaction), `RecomputeRange` (roster/schedule/calendar changes; widens to the ISO week for CWW rosters), dispatch points listed in 06 rule 3 wired through model events/actions.
- **Lock/unlock:** `LockLedger`, `UnlockLedger` actions; the triggers decide.
- **Tests:** constraint tests (locking with a pending out refused, unlock with attestations refused, second claim of a timelog refused, voided timelog refused), pipeline unit tests, job idempotence, recompute ordering T−3..T so the earlier workday claims first.

## Milestone 7 — DTR and attestation (design 06)

- **Views:** `Ledger::view(Period, Work)` returning a `LedgerView` (rows per day, totals, occurrences; overtime view = excess ∩ `Overtime` authority gated by JC 2 s.2015 constants); `Csc` constants class (tier 1 values with effectivity dates) and `LeaveDays::fromMinutes()` lookup seeded from the printed table.
- **UI:** employee month page (self-service for users with `employee_id`; HR for any employee) as the CS Form 48 layout: AM/PM columns, day markers `⁺¹`, `…` for pending, undertime column, totals; print stylesheet (A4, two DTRs per sheet as the paper form); ledger list per month (status: open, pending outs, locked, attested), lock/unlock buttons, attestation panel showing the chain from settings with who signed when; supervisor view (heads see their unit's ledgers).
- **Attestations:** table (`UNIQUE (ledger_id, role)`, role regex CHECK, paired FKs, trigger `attestations_locked`, `REVOKE UPDATE`), `Attest` action resolving who may sign each role from the org tree, `Unattest`.
- **Tests:** renderer cases from the 06 tables, occurrences counting, attestation resolution (employee, supervisor via `Unit.head_id`, head by unit kind, hr by permission), constraint tests.

## Milestone 8 — Settings and audits (00 tier 3, decision 15)

- `settings_valid(jsonb)` function + CHECK on `agencies.settings`; typed `App\Settings\Settings` castable (keys v1: `missing` void|assume, `attestations` ordered roles, `head_kind`); settings page under `agency.manage`.
- `audits` table filled by a generic row-level trigger on rosters, exemptions, overtimes, enrollments, timelog voids, ledger locks, attestations, shifts, schedules; `user_id` from `current_setting('khronoz.user_id', true)` set per request by middleware (`SET LOCAL` inside transactions, `set_config(..., false)` at request start and cleared on terminate under Octane) and per job from Context; audit log screen with filters.

## Milestone 9 — Hardening and ops

- Seeders for onboarding-test agencies A–E with realistic months of timelogs (deterministic faker seed) for demos and performance checks; indexes confirmed with `EXPLAIN` on the roster grid, timelog explorer and DTR queries; Horizon supervisors for `recompute` and `ingest` queues; scheduler for `pull` terminals; platform user management (invite superusers); accessibility review (`design:accessibility-review`) and UX copy pass; SSR for public pages only; `composer audit`; release checklist and `.env` documentation. Phase 2 hand-off notes: mobile token API over the actions, filing/approval workflows, `Template`.

---

## Self-review (done while writing)

- **Spec coverage, Milestone 1:** 01 agencies rule 3 (platform row, partial index, triggers, scope, `Agency::platform()`) → Task 4; 02 rules 1–4 (one authenticatable, employee link column, platform users enter agencies, permissions) → Tasks 5–8; 07 agencies/users DDL → Tasks 4–5; 07 "Laravel notes" (two connections, owner role) → Task 3; decision 15 invite-only logins → Task 7; decision 14 defaults copy → deferred to Milestone 3 where shifts exist (noted in Task 8); `agency_not_platform` trigger → Milestone 2 with the first operational table.
- **Type consistency:** `Tenant::set/forget/agency/id/check/platformId`, `User::allows/isPlatform`, `Permission::implies/grants/group/label`, `Preset::permissions/label`, `InviteUser::handle(array): User`, `CreateAgency::handle(array): Agency`, shared props `auth.user`, `agency`, `agencies`, `flash` are used with the same names in every task.
- **Placeholders:** none; the two `YOUR CALL` points each carry a default so execution never blocks.
- **Architecture review applied:** middleware priority for `SetTenant`, no tenant scope on `User`, fail-closed `AgencyScope`, role provisioning outside migrations, seeding on the app connection, `lower(email)` index through `DB::statement`, Wayfinder plugin placement and `pretypes`, TypeScript strictness note for generated shadcn files.
