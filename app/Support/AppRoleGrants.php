<?php

namespace App\Support;

use Illuminate\Database\Connection;
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

        // Everything the blanket grant above hands out and must not have kept.
        // It runs here as well as from the migrations that own each table,
        // because `db:grant` re-runs apply() and would otherwise silently
        // restore write access every time.
        static::restrict($connection);

        return $role;
    }

    /**
     * Narrow the app role back down on the tables that must not be freely
     * writable.
     *
     * **This is the whole of the immutability guarantee.** `03-terminals.md`
     * rule 1 says a timelog is immutable and nothing is ever pruned, and it is
     * "enforced by privilege" — this method is that privilege. A REVOKE
     * written only into a table's own migration is silently undone by the next
     * deploy, because `apply()` grants CRUD on *all* tables and `db:grant`
     * re-runs it after an owner-role rotation. There would be no error and no
     * failing test; immutability would simply stop existing.
     *
     * So the statements live here, in the class both callers share, and the
     * migrations call this rather than writing their own — the same reasoning
     * that already kept the `migrations` REVOKE out of
     * 0000_00_00_000001_prepare_application_database.
     *
     * Every statement is guarded by `to_regclass`, so a fresh install — where
     * apply() runs long before `timelogs` exists — is a no-op here and the
     * timelogs migration applies it at the right point in dependency order.
     *
     * REVOKE is idempotent, so re-running on an already-restricted database
     * costs nothing, which is exactly what makes `db:grant` a safe recovery.
     */
    public static function restrict(string $connection = 'owner'): void
    {
        $db = DB::connection($connection);

        $role = static::quoteIdentifier(config('database.connections.pgsql.username'));

        // The migrator's own bookkeeping. The app role never runs a migration,
        // so it keeps SELECT and loses the writes the blanket grant gave it.
        static::narrow($db, 'migrations', ["REVOKE INSERT, UPDATE, DELETE ON migrations FROM {$role}"]);

        // A timelog is what the device said. The app may add one and may mark
        // one bad — recording who did so — but it may not change what was
        // recorded, say who punched, or remove the row. `voided_by` is in the
        // grant and `user_id` is not, and that asymmetry is the point: a void
        // must be attributable without being able to rewrite whose punch it
        // was. Re-voiding is refused by timelogs_void_is_final, because a
        // column grant cannot see the row's previous value. The predecessor had four independent ways to delete
        // one of these — a flush verb, a two-year prune scheduled every
        // minute, and cascades from both the scanner and its own self-FK — and
        // this is what makes all four unbuildable rather than merely unwritten.
        static::narrow($db, 'timelogs', [
            "REVOKE DELETE, UPDATE ON timelogs FROM {$role}",
            "GRANT UPDATE (voided_at, reason, voided_by) ON timelogs TO {$role}",
        ]);

        // A run record that can be deleted is a run that can be denied.
        static::narrow($db, 'syncs', ["REVOKE DELETE ON syncs FROM {$role}"]);

        // A signature is added or removed, never edited (07-constraints.md
        // attestations). `at` is when it was made, and REVOKE UPDATE is what
        // makes that column the truth rather than a timestamp that can be
        // rewritten. INSERT and DELETE stay: you certify by inserting a row
        // and you un-certify by removing it, which is also how a locked
        // ledger becomes unlockable again.
        static::narrow($db, 'attestations', ["REVOKE UPDATE ON attestations FROM {$role}"]);
    }

    /**
     * Run $statements only if $table exists, so a fresh install can call
     * restrict() before the later tables are created.
     *
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
