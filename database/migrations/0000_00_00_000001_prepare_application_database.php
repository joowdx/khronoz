<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Runs on the owner connection. The application role is provisioned outside
     * migrations (docker/pgsql/20-create-app-role.sh, README); this migration
     * gives it row privileges on every present and future table — and revokes
     * the write access on `migrations` itself that it must never hold — so
     * later migrations need no grants, only the REVOKEs on timelogs, syncs and
     * attestations. Default privileges follow the role that runs migrations,
     * so if the owner role is ever rotated, re-run `php artisan db:grant`
     * (AppRoleGrants::apply(), the same statements this migration issues)
     * against the new owner — otherwise later migrations' tables would have
     * no default privileges for the app role at all.
     *
     * The REVOKE on `migrations` lives in AppRoleGrants::apply() itself, not
     * here, on purpose: this migration only ever runs once per database (a
     * migration is tracked by filename, not content, so an edit here would
     * never re-execute on a database that already ran it), while `db:grant`
     * must be able to restore that REVOKE by hand after an owner-role
     * rotation re-grants it. A statement that belongs to both callers has to
     * live in the shared class, not in the one-shot migration.
     */
    public function up(): void
    {
        AppRoleGrants::apply();
    }

    /** Grants are harmless to keep and other databases may share the role: intentionally a no-op. */
    public function down(): void {}
};
