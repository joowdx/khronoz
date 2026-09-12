# khronoz

Scheduling and Daily Time Record system for Philippine government HR offices — Laravel 13, Inertia 3, React 19, Postgres 18.

## Setup

Dependencies (Postgres, Valkey, RustFS, Mailpit) run in Docker; the application itself runs on the host.

```bash
docker compose up -d   # khronoz-pgsql, khronoz-valkey, khronoz-rustfs, khronoz-mailpit
composer setup         # install, .env, app key, composer migrate, npm install, Wayfinder, npm run build
php artisan db:seed
php artisan dev
```

- App: http://localhost:43080
- Mailpit: http://localhost:43825
- Horizon: http://localhost:43080/horizon (open to everyone locally; outside local, restricted to platform superusers — see `app/Support/Dashboard.php`)

Dev superuser, seeded by `db:seed` (local only): `superuser@khronoz.test` / `password`.

Run the tests with `php artisan test --compact`.

`composer setup` runs `composer install`, copies `.env.example` to `.env`, generates the app key, runs `composer migrate` (below), installs npm dependencies, generates the Wayfinder route and action modules (`php artisan wayfinder:generate --with-form` — they are gitignored, so a fresh clone has nothing to import until this runs) and builds the front end.

### Database roles

Migrations run as the database owner (`khronoz`). The application itself connects as a separate, restricted role, `chronoz`, which cannot alter schema. It is also revoked from writing to `migrations` itself (`0000_00_00_000001_prepare_application_database`); later milestones REVOKE further columns and tables the same way as each one ships (`docs/design/07-constraints.md`). Never run migrations as the app role directly — a freshly created table has no grants for it yet, and later migrations REVOKE privileges a superuser connection would otherwise still have.

- Always migrate with `composer migrate` (`php artisan migrate --force --database=owner`), never a bare `php artisan migrate`.
- **Fresh Docker volume:** `docker/pgsql/20-create-app-role.sh` creates the `chronoz` role automatically the first time the `khronoz-pgsql` volume initializes. Nothing to do.
- **Production**, with no Docker Postgres: create the role directly on the real database, then run `composer migrate` once against it — the first migration grants `chronoz` row-level privileges on every present and future table.
  ```sql
  CREATE ROLE chronoz LOGIN PASSWORD '…';
  ```
- **After rotating the owner role**: a later migration's default privileges follow whichever role ran it, not the original owner that first granted the app role access, so a table created after the rotation would otherwise have no grants for it at all. Run `php artisan db:grant` once against the new owner to re-issue the same grants (`App\Support\AppRoleGrants`, idempotent).

`DB_OWNER_USERNAME` / `DB_OWNER_PASSWORD` exist only in dev `.env` and the deploy/migrate step, and have no fallback credentials — the connection simply fails to authenticate if they are missing where it is actually used. **Leave them unset wherever Octane and Horizon actually run**: even if they were set there, `AppServiceProvider::guardOwnerConnection()` refuses to resolve `DB::connection('owner')` outside a console run, so a web or queue process can no longer bypass a REVOKE the migrations put in place.

### Known gap: `npm run lint`

`npm run lint` does not currently work: `typescript-eslint@8.70.0` and its parser both refuse to load under this project's TypeScript 7 (their peer range is `>=4.8.4 <6.1.0`). See the comment at the top of `eslint.config.js`; upstream tracking is [typescript-eslint#10940](https://github.com/typescript-eslint/typescript-eslint/issues/10940). Until that lands, the front-end verification gate is:

```bash
npm run format && npm run types && npm run build
```

## Documentation

Design docs live under `docs/design/` (start with `00-principles.md`), reference material the design answers to under `docs/reference/`, and durable coding conventions under `.ai/rules/` (start with `index.md`).

## Account security and social sign-in

Account settings live at `/settings/profile`, `/settings/security`, and `/settings/connections`. They manage the signed-in account even when a platform user has entered another agency. Account email changes use a one-hour signed link; the old email remains active until confirmation. Credential changes require reauthentication within five minutes. Keep the queue worker and SMTP delivery running for verification and change notifications; use Mailpit locally, never a logging mailer for authentication links.

Fortify and the official Laravel passkey packages provide security primitives through application-owned routes. Package routes are disabled; registration remains invitation-only. Activating an invitation and acknowledging current legal documents precede business access. An existing password remains the recovery option. Password resets preserve authenticator 2FA.

### Provider configuration

Each provider is independently enabled only when all three configuration values are nonblank. Configure these existing variables and rebuild the configuration cache after a deployment change:

| Provider | Required variables | Callback route |
| --- | --- | --- |
| Google | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | `GET /auth/google/callback` |
| Apple | `APPLE_CLIENT_ID`, `APPLE_CLIENT_SECRET`, `APPLE_REDIRECT_URI` | `POST /auth/apple/callback` |

Use the exact registered callback URI and production HTTPS domain. Apple uses the generated client-secret JWT; arrange renewal before it expires. Its Services ID/application setup and Google consent-screen/application setup remain operator tasks. See [Google's server-flow setup](https://developers.google.com/identity/protocols/oauth2/web-server) and [Apple's web authentication setup](https://developer.apple.com/help/account/capabilities/configure-sign-in-with-apple-for-the-web/).

Users must first sign in with their activated account and explicitly connect a provider in Account settings. There is no public registration or automatic email matching. Changing or removing provider configuration hides sign-in/connect controls; existing connections can still be disconnected locally. Unlinking does not revoke permissions at Google or Apple.

Apple's POST callback deliberately has no web/session middleware. It caches an encrypted payload for two minutes and redirects to a same-origin completion GET, where the original session's single-use state, linking owner, and token nonce are checked. Keep global CSRF and SameSite cookie defaults intact. Use a shared cache supporting atomic locks and a server-side session driver (database/Redis); do not use the cookie session driver. Provider state and pending 2FA expire in five minutes. No provider access/refresh tokens or raw profiles are persisted.

### Passkey deployment

Set `APP_URL` to the canonical HTTPS origin. `config/fortify.php` derives the exact allowed origin and relying-party hostname from it; scheme and port are part of the origin. Localhost can be used for local development. Do not casually change the relying-party domain after enrollment. Set a stable, secret `PASSKEYS_USER_HANDLE_SECRET` before initial enrollment, and preserve it across app-key rotations; it falls back to `APP_KEY` if absent. Passkey user verification and resident credentials are required; ceremonies expire after one minute and are single-use. The server stores public verification material, never private keys or biometrics.

Keep `APP_KEY` and previous decryption keys available according to the deployment's key-rotation policy: authenticator secrets, recovery codes, encrypted notification jobs and Apple relays need decryption. Limit access to session/cache stores, backups and queued mail. Telescope excludes authentication paths, credential queries and account-verification notifications, and masks authentication session state. Configure reverse proxies/APM/access logs to redact callback query parameters and signed verification URLs; avoid request/response body capture for authentication endpoints.

Automated coverage uses simulated provider HTTP responses, signed Apple test JWTs and software WebAuthn authenticators. It does not substitute for a live Google/Apple credential test or a physical-device/browser interoperability check before release.

### Legal publication

Current documents and hashes live in `resources/legal/manifest.json`. The account-security privacy revision is a new draft; the previous version and its acknowledgments remain intact. Run `php artisan legal:check` to verify every version, and `php artisan legal:check --published` before launch. Operator/contact/location/effective-date placeholders, retention/disposal procedures and the agency processing agreement remain launch dependencies; drafts do not unlock production business access. The detailed cross-check is in `docs/reference/privacy-rules.md`.
