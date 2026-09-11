<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One signature per role per ledger (docs/design/06-attendance.md
     * Attestation rules 1–6). Who may sign which role is Milestone 7; this
     * table exists now because `ledgers_unlock_clean` already references it.
     *
     * There is deliberately **no `created_at` and no `updated_at`**. `at` is
     * when the signature was made, which is the same instant the row was
     * created, and two columns holding one fact is the mixed-concern defect
     * docs/reference/clockwork-audit.md records. `updated_at` would be worse
     * than redundant: REVOKE UPDATE means it can never move, so it would be
     * a column that permanently lies about being maintained.
     *
     * `user_id` is paired with `agency_id`, unlike suspensions, exemptions
     * and overtimes. Those three record a platform superuser doing data
     * entry inside an agency they entered; a signature must come from
     * inside the agency, so the pair is the constraint, and there is no
     * `actor_of_agency` trigger because the FK already says it.
     *
     * The REVOKE lives in AppRoleGrants::restrict(), not here (decision 41).
     */
    public function up(): void
    {
        Schema::create('attestations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('ledger_id');
            $table->string('role');
            $table->ulid('user_id');
            $table->timestamp('at');

            $table->unique(['id', 'agency_id']);
            $table->unique(['ledger_id', 'role']);

            $table->foreign(['ledger_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('ledgers')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['user_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('users')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // Shape only. Which strings are legal is the agency's
        // settings.attestations list, checked by the application
        // (06-attendance.md rule 2) — decision 12's point is that the
        // signing chain is agency data, not schema.
        DB::statement("ALTER TABLE attestations ADD CONSTRAINT attestations_role_valid CHECK (role ~ '^[a-z_]{1,32}$')");

        // Function created in 0001_01_01_000028_prepare_attendance.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER attestations_locked
                BEFORE INSERT ON attestations
                FOR EACH ROW EXECUTE FUNCTION attestations_locked();
        SQL);

        // A signature is added or removed, never edited. The statement lives
        // in AppRoleGrants::restrict() so `db:grant` restores it.
        AppRoleGrants::restrict();
    }

    /**
     * The trigger goes with the table; `attestations_locked()` belongs to
     * 0001_01_01_000028_prepare_attendance and is dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('attestations');
    }
};
