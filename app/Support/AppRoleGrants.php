<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AppRoleGrants
{
    public static function apply(string $connection = 'owner'): string
    {
        $db = DB::connection($connection);

        $role = config('database.connections.pgsql.username');

        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $role)) {
            throw new RuntimeException('DB_USERNAME must be a plain lowercase identifier.');
        }

        if ($db->selectOne('select 1 as present from pg_roles where rolname = ?', [$role]) === null) {
            throw new RuntimeException("Role {$role} does not exist. Create it first: see README, Database roles.");
        }

        $role = static::quoteIdentifier($role);

        $database = static::quoteIdentifier($db->getDatabaseName());
        $owner = static::quoteIdentifier($db->selectOne('select current_user as name')->name);

        $db->statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        $db->statement("GRANT CONNECT ON DATABASE {$database} TO {$role}");
        $db->statement("GRANT USAGE ON SCHEMA public TO {$role}");
        $db->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}");
        $db->statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$role}");
        $db->statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
        $db->statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$role}");

        static::restrict($connection);

        return $role;
    }

    public static function restrict(string $connection = 'owner'): void
    {
        $db = DB::connection($connection);

        $role = static::quoteIdentifier(config('database.connections.pgsql.username'));

        static::narrow($db, 'migrations', ["REVOKE INSERT, UPDATE, DELETE ON migrations FROM {$role}"]);

        static::narrow($db, 'timelogs', [
            "REVOKE DELETE, UPDATE ON timelogs FROM {$role}",
            "GRANT UPDATE (voided_at, reason, voided_by) ON timelogs TO {$role}",
        ]);

        // A run record that can be deleted is a run that can be denied.
        static::narrow($db, 'syncs', ["REVOKE DELETE ON syncs FROM {$role}"]);

        static::narrow($db, 'attestations', ["REVOKE UPDATE ON attestations FROM {$role}"]);
    }

    /**
     * @param  list<string>  $statements
     */
    protected static function narrow(Connection $db, string $table, array $statements): void
    {
        if ($db->selectOne('select to_regclass(?) as present', ['public.'.$table])->present === null) {
            return;
        }

        foreach ($statements as $statement) {
            $db->statement($statement);
        }
    }

    public static function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
