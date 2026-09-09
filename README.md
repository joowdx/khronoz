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

Migrations run as the database owner. The application itself connects as a separate, restricted role, `khronoz_app`, which cannot alter schema and is further revoked from a handful of columns and tables the migrations lock down (`docs/design/07-constraints.md`). Never run migrations as the app role directly — a freshly created table has no grants for it yet, and later migrations REVOKE privileges a superuser connection would otherwise still have.

- Always migrate with `composer migrate` (`php artisan migrate --database=owner`), never a bare `php artisan migrate`.
- **Fresh Docker volume:** `docker/pgsql/20-create-app-role.sh` creates the `khronoz_app` role automatically the first time the `khronoz-pgsql` volume initializes. Nothing to do.
- **Existing volume** where the role is missing (Postgres only runs `/docker-entrypoint-initdb.d` scripts against an empty data directory): create it by hand once, then migrate as usual.
  ```bash
  docker compose exec pgsql psql -U sail -d khronoz -c "CREATE ROLE khronoz_app LOGIN PASSWORD 'password';"
  ```
- **Production**, with no Docker Postgres: create the role directly on the real database, then run `composer migrate` once against it — the first migration grants `khronoz_app` row-level privileges on every present and future table.
  ```sql
  CREATE ROLE khronoz_app LOGIN PASSWORD '…';
  ```

`DB_OWNER_USERNAME` / `DB_OWNER_PASSWORD` exist only in dev `.env` and the deploy/migrate step. **Leave them unset wherever Octane and Horizon actually run** — if a running app process has them set, `DB::connection('owner')` bypasses every REVOKE the migrations put in place.

### Known gap: `npm run lint`

`npm run lint` does not currently work: `typescript-eslint@8.70.0` and its parser both refuse to load under this project's TypeScript 7 (their peer range is `>=4.8.4 <6.1.0`). See the comment at the top of `eslint.config.js`; upstream tracking is [typescript-eslint#10940](https://github.com/typescript-eslint/typescript-eslint/issues/10940). Until that lands, the front-end verification gate is:

```bash
npm run format && npm run types && npm run build
```

## Documentation

Design docs live under `docs/design/` (start with `00-principles.md`), reference material the design answers to under `docs/reference/`, and durable coding conventions under `.ai/rules/` (start with `index.md`).
