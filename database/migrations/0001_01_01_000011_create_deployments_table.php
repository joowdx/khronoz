<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paired keys prevent cross-agency placements; parent rows represent substantive placements.
     */
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('employee_id');
            $table->ulid('workgroup_id');
            // A null parent is the substantive placement; a set parent is a reassignment.
            $table->ulid('parent_id')->nullable();
            $table->date('starts');
            $table->date('ends')->nullable();
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

            // Enables the paired parent key that requires the same employee.
            $table->unique(['id', 'employee_id']);

            $table->foreign(['employee_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('employees')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['workgroup_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('workgroups')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            // RESTRICT prevents deleting a placement before its reassignments.
            $table->foreign(['parent_id', 'employee_id'])
                ->references(['id', 'employee_id'])
                ->on('deployments')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // NOT NULL keeps date ordering and exclusion ranges meaningful.
        DB::statement('ALTER TABLE deployments ADD CONSTRAINT deployments_dates_ordered CHECK (ends IS NULL OR ends >= starts)');

        // This declarative guard owns self-parent SQLSTATE 23514 before the trigger.
        DB::statement('ALTER TABLE deployments ADD CONSTRAINT deployments_parent_not_self CHECK (parent_id IS DISTINCT FROM id)');

        // Partial exclusions preserve one substantive placement and one reassignment at a time.
        // Inclusive bounds include shared dates; immediate checks remain catchable in SAVEPOINT tests.
        DB::statement("ALTER TABLE deployments ADD CONSTRAINT deployments_no_overlap EXCLUDE USING gist (employee_id WITH =, daterange(starts, ends, '[]') WITH &&) WHERE (parent_id IS NULL)");
        DB::statement("ALTER TABLE deployments ADD CONSTRAINT deployments_no_overlapping_movements EXCLUDE USING gist (employee_id WITH =, daterange(starts, ends, '[]') WITH &&) WHERE (parent_id IS NOT NULL)");

        // The table owns its trigger; shared validation stays in the preparation migration.
        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER deployments_nested
                AFTER INSERT OR UPDATE OF parent_id, starts, ends ON deployments
                DEFERRABLE INITIALLY IMMEDIATE
                FOR EACH ROW EXECUTE FUNCTION deployments_nested();
        SQL);
    }

    /**
     * The trigger goes with the table; `deployments_nested()` belongs to
     * 0001_01_01_000008_prepare_organization and is dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
