<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deployment visibility is range-based, so a split month cannot have one deployment foreign key.
     */
    public function up(): void
    {
        Schema::create('ledgers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('employee_id');
            $table->ulid('cadence_id')->nullable();
            $table->date('starts');
            $table->date('ends');
            $table->string('scope')->default('all');
            $table->unsignedInteger('revision')->default(1);
            $table->ulid('locked_by');
            $table->timestamp('unlocked_at')->nullable();
            $table->ulid('unlocked_by')->nullable();
            $table->jsonb('calculation')->nullable();
            $table->jsonb('identity')->nullable();
            $table->jsonb('policy')->nullable();
            $table->jsonb('signers')->nullable();
            $table->timestamp('locked_at');
            $table->timestamps();

            $table->unique(['id', 'agency_id']);
            $table->unique(['employee_id', 'starts', 'ends', 'scope', 'revision']);
            $table->foreign(['cadence_id', 'agency_id'])->references(['id', 'agency_id'])->on('cadences')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('locked_by')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('unlocked_by')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();

            $table->foreign(['employee_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('employees')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE ledgers
                ADD CONSTRAINT ledgers_range_valid CHECK (ends >= starts),
                ADD CONSTRAINT ledgers_range_length CHECK (ends - starts <= 30),
                ADD CONSTRAINT ledgers_scope_valid CHECK (scope IN ('regular', 'overtime', 'all')),
                ADD CONSTRAINT ledgers_revision_positive CHECK (revision > 0),
                ADD CONSTRAINT ledgers_unlock_paired CHECK ((unlocked_at IS NULL) = (unlocked_by IS NULL)),
                ADD CONSTRAINT ledgers_lock_paired CHECK ((locked_at IS NULL) = (locked_by IS NULL)),
                ADD CONSTRAINT ledgers_snapshots_complete CHECK (
                    locked_at IS NULL OR (
                        calculation IS NOT NULL AND identity IS NOT NULL AND policy IS NOT NULL AND signers IS NOT NULL
                        AND jsonb_typeof(calculation) = 'object' AND jsonb_typeof(identity) = 'object'
                        AND jsonb_typeof(policy) = 'object' AND jsonb_typeof(signers) = 'array'
                    )
                ),
                ADD CONSTRAINT ledgers_unlock_after_lock CHECK (unlocked_at IS NULL OR unlocked_at >= locked_at);
            CREATE UNIQUE INDEX ledgers_one_active ON ledgers (employee_id, starts, ends, scope) WHERE unlocked_at IS NULL;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledgers_lock_complete
                BEFORE INSERT OR UPDATE ON ledgers
                FOR EACH ROW WHEN (NEW.locked_at IS NOT NULL)
                EXECUTE FUNCTION ledgers_lock_complete();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledgers_unlock_clean
                BEFORE UPDATE OF unlocked_at ON ledgers
                FOR EACH ROW WHEN (NEW.unlocked_at IS NOT NULL)
                EXECUTE FUNCTION ledgers_unlock_clean();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledgers_immutable
                BEFORE UPDATE OR DELETE ON ledgers
                FOR EACH ROW EXECUTE FUNCTION ledgers_immutable();
        SQL);

        // Attach these triggers after ledgers exist because their functions query it.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER deployments_frozen_month
                BEFORE INSERT OR UPDATE OR DELETE ON deployments
                FOR EACH ROW EXECUTE FUNCTION deployments_frozen_month();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER exemptions_frozen_month
                BEFORE INSERT OR UPDATE OR DELETE ON exemptions
                FOR EACH ROW EXECUTE FUNCTION exemptions_frozen_month();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER overtimes_frozen_month
                BEFORE INSERT OR UPDATE OR DELETE ON overtimes
                FOR EACH ROW EXECUTE FUNCTION overtimes_frozen_month();
        SQL);
    }

    /**
     * The ledgers triggers go with the table. The three frozen-month
     * triggers live on other tables, so they are dropped explicitly; their
     * functions belong to 0001_01_01_000028_prepare_attendance and are
     * dropped only there.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS deployments_frozen_month ON deployments');
        DB::unprepared('DROP TRIGGER IF EXISTS exemptions_frozen_month ON exemptions');
        DB::unprepared('DROP TRIGGER IF EXISTS overtimes_frozen_month ON overtimes');

        Schema::dropIfExists('ledgers');
    }
};
