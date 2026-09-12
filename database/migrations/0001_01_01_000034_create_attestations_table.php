<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attestations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('ledger_id');
            $table->string('role');
            $table->unsignedSmallInteger('sequence');
            $table->ulid('user_id');
            $table->string('name');
            $table->timestamp('at');
            $table->ulid('withdrawn_by')->nullable();
            $table->timestamp('withdrawn_at')->nullable();

            $table->unique(['id', 'agency_id']);

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

            $table->foreign('withdrawn_by')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE attestations
                ADD CONSTRAINT attestations_role_valid CHECK (role IN ('employee', 'supervisor', 'head', 'timekeeper')),
                ADD CONSTRAINT attestations_sequence_positive CHECK (sequence > 0),
                ADD CONSTRAINT attestations_name_present CHECK (length(name) > 0),
                ADD CONSTRAINT attestations_withdrawal_paired CHECK ((withdrawn_at IS NULL) = (withdrawn_by IS NULL));
            CREATE UNIQUE INDEX attestations_active_sequence ON attestations (ledger_id, sequence) WHERE withdrawn_at IS NULL;
            CREATE UNIQUE INDEX attestations_active_role ON attestations (ledger_id, role) WHERE withdrawn_at IS NULL;
        SQL);

        // Function created in 0001_01_01_000028_prepare_attendance.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER attestations_locked
                BEFORE INSERT ON attestations
                FOR EACH ROW EXECUTE FUNCTION attestations_locked();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER attestations_immutable
                BEFORE UPDATE OR DELETE ON attestations
                FOR EACH ROW EXECUTE FUNCTION attestations_immutable();
        SQL);

        // A signature is added or removed, never edited. The statement lives
        // in AppRoleGrants::restrict() so `db:grant` restores it.
        AppRoleGrants::restrict();
    }

    public function down(): void
    {
        Schema::dropIfExists('attestations');
    }
};
