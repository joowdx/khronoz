<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dated exclusions preserve the one employee for each device UID at any time.
     */
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('employee_id');
            $table->ulid('terminal_id');
            // Preserve the raw device UID; resolution joins this exact value.
            $table->string('uid');
            $table->string('privilege');
            $table->date('starts');
            // A null upper bound represents a current enrollment.
            $table->date('ends')->nullable();
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

            $table->foreign(['employee_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('employees')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['terminal_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('terminals')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // This paired target makes incorrect timelog resolution fail with 23503.
        DB::statement('ALTER TABLE enrollments ADD CONSTRAINT enrollments_resolution_key UNIQUE (id, employee_id, terminal_id, uid)');

        DB::statement("ALTER TABLE enrollments ADD CONSTRAINT enrollments_privilege_valid CHECK (privilege IN ('user', 'enroller', 'admin', 'superadmin'))");

        DB::statement('ALTER TABLE enrollments ADD CONSTRAINT enrollments_dates_ordered CHECK (ends IS NULL OR ends >= starts)');

        // This exclusion makes device-UID resolution deterministic.
        DB::statement(<<<'SQL'
            ALTER TABLE enrollments ADD CONSTRAINT enrollments_uid_one_person
                EXCLUDE USING gist (
                    terminal_id WITH =,
                    uid WITH =,
                    daterange(starts, ends, '[]') WITH &&
                )
        SQL);

        // The reciprocal exclusion prevents split identities on one terminal.
        DB::statement(<<<'SQL'
            ALTER TABLE enrollments ADD CONSTRAINT enrollments_one_uid_per_person
                EXCLUDE USING gist (
                    employee_id WITH =,
                    terminal_id WITH =,
                    daterange(starts, ends, '[]') WITH &&
                )
        SQL);

        // Attach the re-resolution trigger only after its timelogs target exists.
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
