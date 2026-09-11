---
paths:
  - 'database/migrations/**'
---

# Migrations

## Migrations run on the owner connection
Run `composer migrate` (= `php artisan migrate --database=owner`); a MigrationsStarted listener refuses any other connection. The app connection is the `chronoz` role, provisioned by docker/pgsql/20-create-app-role.sh, never by a migration; `0000_00_00_000001_prepare_application_database` grants it CRUD on future tables via default privileges. Tables that must be immutable call AppRoleGrants::restrict() from their own migration — never their own REVOKE statements (decision 41): db:grant re-runs apply(), which grants CRUD on every table, so a REVOKE written only in a migration is silently undone by the next deploy. Seeders never truncate(). Tests override migrateFreshUsing() and migrateDatabases() in tests/TestCase.php.

## Migration functions must be CREATE OR REPLACE FUNCTION
db:wipe (which migrate:fresh runs) drops tables, views and types via one combined DROP ... CASCADE statement per kind — e.g. Postgres's compileDropAllTables() emits a single DROP TABLE t1, t2, ... CASCADE covering every table at once, not one statement per table — but never drops functions. A plain CREATE FUNCTION in a migration therefore collides with itself on the second and every later migrate:fresh against the same database. Always use CREATE OR REPLACE FUNCTION for functions created in a migration (e.g. permissions_valid, agencies_platform_row). Triggers may stay plain CREATE TRIGGER, since Postgres drops a trigger along with the table it is on. down() should still DROP FUNCTION IF EXISTS with the argument types, so a real rollback still cleans up.

## Ordered pre-deployment migration names
Before deployment, migration filenames use the deterministic `0001_01_01_######` sequence in dependency order, rather than wall-clock timestamps. Keep only `0000_00_00_000000_prepare_telescope_database` and `0000_00_00_000001_prepare_application_database` before that sequence. Fold unreleased schema additions into the relevant create migration instead of adding standalone link or alteration migrations.

## timelogs_enrollment_foreign defers its update check on purpose
Do not "fix" `timelogs_enrollment_foreign` back to `ON UPDATE RESTRICT`. It is deliberately `ON DELETE RESTRICT ON UPDATE NO ACTION DEFERRABLE INITIALLY DEFERRED` (decision 43).

Why: an enrollment's `uid`, `terminal_id` and `employee_id` must stay correctable — a timekeeper enrolling someone as `1102` when the device reports `01102` is ordinary data entry. Under ON UPDATE RESTRICT the FK refused the parent's UPDATE before `enrollments_reresolve` could repair the children, making three of the five events that trigger is declared for unreachable.

The asymmetry works because Postgres keeps a RESTRICT check immediate even on a DEFERRABLE constraint (verified live): delete still raises 23001 at the statement, update defers to COMMIT.

Consequence for tests: an insert-side violation of this FK surfaces at COMMIT, not at the statement, so `assertDatabaseRefuses` needs `SET CONSTRAINTS ALL IMMEDIATE` inside the closure to see it.

Related: `enrollments_reresolve` must loop over the NEW `(terminal_id, uid)` pair AND the OLD one on update. `timelogs.uid` is never rewritten (decision 42), so without the OLD pass a corrected uid leaves old punches attributed through an enrollment that no longer claims them.
