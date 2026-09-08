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

        // $role is already regex-validated above (it is always ours, khronoz_app);
        // routing it through the same quoting helper too is defence in depth, not a
        // fix, and costs nothing here since a regex-validated value has no characters
        // left for quoting to escape.
        $role = $this->quoteIdentifier($role);

        // $database and $owner are read back from Postgres itself (the configured
        // database name, and `select current_user`) rather than typed by us, so —
        // unlike $role — they cannot be held to a fixed lowercase pattern: a
        // legitimate database or role name may contain uppercase letters or hyphens
        // once quoted. Quote them instead of regex-validating them.
        $database = $this->quoteIdentifier(DB::getDatabaseName());
        $owner = $this->quoteIdentifier(DB::selectOne('select current_user as name')->name);

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("GRANT CONNECT ON DATABASE {$database} TO {$role}");
        DB::statement("GRANT USAGE ON SCHEMA public TO {$role}");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$role}");
    }

    /** Grants are harmless to keep and other databases may share the role: intentionally a no-op. */
    public function down(): void {}

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
    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
