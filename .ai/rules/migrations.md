---
paths:
  - 'database/migrations/**'
---

# Migrations

## Migrations run on the owner connection
Run `composer migrate` (= `php artisan migrate --database=owner`); a MigrationsStarted listener refuses any other connection. The app connection is the `khronoz_app` role, provisioned by docker/pgsql/20-create-app-role.sh, never by a migration; `0000_00_00_000001_prepare_database` grants it CRUD on future tables via default privileges. Tables that must be immutable REVOKE in their own migration. Seeders never truncate(). Tests override migrateFreshUsing() and migrateDatabases() in tests/TestCase.php.
