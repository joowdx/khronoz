<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The GRANT, REVOKE and ALTER DEFAULT PRIVILEGES statements that give the
 * app role row access on every present and future table and sequence,
 * except the write access on `migrations` it must never hold. Shared by
 * 0000_00_00_000001_prepare_application_database (a fresh install) and `php artisan
 * db:grant` (GrantAppRolePrivileges): a rotated owner role's later migrations
 * create tables whose default privileges follow whichever role ran *them*,
 * not the original owner that first granted the app role access, so re-running
 * these statements is how a deploy recovers after that rotation without a
 * confusing 42501 at runtime. Both callers must run on the owner connection.
 */
class AppRoleGrants
{
    /** Apply the grants against $connection (must be the owner connection). Returns the quoted app role identifier. */
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

        // $role is already regex-validated above (it is always ours, chronoz);
        // routing it through the same quoting helper too is defence in depth, not a
        // fix, and costs nothing here since a regex-validated value has no characters
        // left for quoting to escape.
        $role = static::quoteIdentifier($role);

        // $database and $owner are read back from Postgres itself (the configured
        // database name, and `select current_user`) rather than typed by us, so —
        // unlike $role — they cannot be held to a fixed lowercase pattern: a
        // legitimate database or role name may contain uppercase letters or hyphens
        // once quoted. Quote them instead of regex-validating them.
        $database = static::quoteIdentifier($db->getDatabaseName());
        $owner = static::quoteIdentifier($db->selectOne('select current_user as name')->name);

        $db->statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        $db->statement("GRANT CONNECT ON DATABASE {$database} TO {$role}");
        $db->statement("GRANT USAGE ON SCHEMA public TO {$role}");
        $db->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}");
        $db->statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$role}");
        $db->statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
        $db->statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$role}");

        // migrations is the one table the migrator itself must be able to
        // write; the app role never runs a migration, so it keeps SELECT (in
        // case anything needs to read migration history) but not the write
        // privileges the blanket grant above just gave it. REVOKE is
        // naturally idempotent, so re-running it here on an already-restricted
        // database is harmless — that is what lets `db:grant` restore this
        // narrower state after an owner-role rotation, since the blanket
        // GRANT ON ALL TABLES above re-grants the app role write access to
        // every existing table, `migrations` included, on every re-run.
        $db->statement("REVOKE INSERT, UPDATE, DELETE ON migrations FROM {$role}");

        return $role;
    }

    /**
     * Quote a Postgres identifier for safe interpolation into DDL.
     *
     * Doubles any embedded double quote and wraps the result in double quotes —
     * Postgres's own identifier-quoting rule — rather than validating the value
     * against a regex. $database and $owner are read back from the database itself,
     * not typed by us, so a regex strict enough to be safe (like the one guarding
     * $role above) would also reject legitimate identifiers it doesn't anticipate,
     * such as an uppercase or hyphenated database name.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
