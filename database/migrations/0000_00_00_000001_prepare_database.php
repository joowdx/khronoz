<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Runs on the owner connection. The application role is provisioned outside
     * migrations (docker/pgsql/20-create-app-role.sh, README); this migration
     * gives it row privileges on every present and future table so later
     * migrations need no grants, only the REVOKEs on timelogs, syncs and
     * attestations. Default privileges follow the role that runs migrations,
     * so if the owner role is ever rotated, re-run `php artisan db:grant`
     * (AppRoleGrants::apply(), the same statements this migration issues)
     * against the new owner — otherwise later migrations' tables would have
     * no default privileges for khronoz_app at all.
     */
    public function up(): void
    {
        $role = AppRoleGrants::apply();

        // migrations is the one table the migrator itself must be able to
        // write; khronoz_app never runs a migration, so it keeps SELECT (in
        // case anything needs to read migration history) but not the write
        // privileges the blanket grant above just gave it.
        DB::statement("REVOKE INSERT, UPDATE, DELETE ON migrations FROM {$role}");
    }

    /** Grants are harmless to keep and other databases may share the role: intentionally a no-op. */
    public function down(): void {}
};
