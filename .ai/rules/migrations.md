---
paths:
  - 'database/migrations/**'
---

# Migrations

## Migrations run on the owner connection
Run `composer migrate` (= `php artisan migrate --database=owner`); a MigrationsStarted listener refuses any other connection. The app connection is the `chronoz` role, provisioned by docker/pgsql/20-create-app-role.sh, never by a migration; `0000_00_00_000001_prepare_application_database` grants it CRUD on future tables via default privileges. Tables that must be immutable REVOKE in their own migration. Seeders never truncate(). Tests override migrateFreshUsing() and migrateDatabases() in tests/TestCase.php.

## Migration functions must be CREATE OR REPLACE FUNCTION
db:wipe (which migrate:fresh runs) drops tables, views and types via one combined DROP ... CASCADE statement per kind — e.g. Postgres's compileDropAllTables() emits a single DROP TABLE t1, t2, ... CASCADE covering every table at once, not one statement per table — but never drops functions. A plain CREATE FUNCTION in a migration therefore collides with itself on the second and every later migrate:fresh against the same database. Always use CREATE OR REPLACE FUNCTION for functions created in a migration (e.g. permissions_valid, agencies_platform_row). Triggers may stay plain CREATE TRIGGER, since Postgres drops a trigger along with the table it is on. down() should still DROP FUNCTION IF EXISTS with the argument types, so a real rollback still cleans up.

## Ordered pre-deployment migration names
Before deployment, migration filenames use the deterministic `0001_01_01_######` sequence in dependency order, rather than wall-clock timestamps. Keep only `0000_00_00_000000_prepare_telescope_database` and `0000_00_00_000001_prepare_application_database` before that sequence. Fold unreleased schema additions into the relevant create migration instead of adding standalone link or alteration migrations.
