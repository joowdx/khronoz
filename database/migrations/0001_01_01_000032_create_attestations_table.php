<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A signature must be from its agency; `at` is the immutable creation time.
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

        // Valid signing roles are agency configuration, not schema.
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
