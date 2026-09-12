<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sole cascade removes derived punches with their workday.
     */
    public function up(): void
    {
        Schema::create('punches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('workday_id');
            $table->ulid('employee_id');
            $table->smallInteger('slot');
            $table->string('kind');
            $table->timestamp('expected_at')->nullable();
            $table->ulid('timelog_id')->nullable();
            $table->timestamp('actual_at')->nullable();
            $table->smallInteger('deviation')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['id', 'agency_id']);
            $table->unique(['workday_id', 'slot', 'kind']);

            $table->foreign(['workday_id', 'employee_id'])
                ->references(['id', 'employee_id'])
                ->on('workdays')
                ->cascadeOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['timelog_id', 'employee_id'])
                ->references(['id', 'employee_id'])
                ->on('timelogs')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // One timelog fills one slot side, ever. Partial: a missed punch has
        // no timelog, and an unlimited number of those may exist.
        DB::statement('CREATE UNIQUE INDEX punches_timelog ON punches (timelog_id) WHERE timelog_id IS NOT NULL');

        DB::statement("ALTER TABLE punches ADD CONSTRAINT punches_kind_valid CHECK (kind IN ('in', 'out'))");
        DB::statement('ALTER TABLE punches ADD CONSTRAINT punches_slot_positive CHECK (slot > 0)');
        DB::statement('ALTER TABLE punches ADD CONSTRAINT punches_actual_pairs_timelog CHECK ((timelog_id IS NULL) = (actual_at IS NULL))');
        // Deviation exists only when actual and expected times both exist.
        DB::statement('ALTER TABLE punches ADD CONSTRAINT punches_deviation_pairs_both CHECK ((deviation IS NULL) = (actual_at IS NULL OR expected_at IS NULL))');

        // A row with neither instant is a punch that records nothing.
        DB::statement('ALTER TABLE punches ADD CONSTRAINT punches_records_a_time CHECK (expected_at IS NOT NULL OR actual_at IS NOT NULL)');

        // Inserts alone are guarded; recomputation uncounts a later-voided timelog.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER punches_timelog_live
                BEFORE INSERT ON punches
                FOR EACH ROW EXECUTE FUNCTION punches_timelog_live();
        SQL);

        // This lock guard prevents rewriting a workday's punch chain.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER punches_ledger_open
                BEFORE INSERT OR UPDATE OR DELETE ON punches
                FOR EACH ROW EXECUTE FUNCTION punches_ledger_open();
        SQL);
    }

    /**
     * The trigger goes with the table; `punches_timelog_live()` belongs to
     * 0001_01_01_000028_prepare_attendance and is dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('punches');
    }
};
