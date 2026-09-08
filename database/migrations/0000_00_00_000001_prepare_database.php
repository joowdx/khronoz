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
