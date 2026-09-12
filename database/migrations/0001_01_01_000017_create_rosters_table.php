<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exclusion permits one dated roster per employee, preserving overrides as history.
     */
    public function up(): void
    {
        Schema::create('rosters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('employee_id');
            $table->ulid('schedule_id');
            // The cohort this assignment came from; null for an ad-hoc set
            // (a tag filter plus select-all). It records *provenance*, not a
            // rule about what it produced — see the note below.
            $table->ulid('team_id')->nullable();
            $table->date('anchor');
            $table->date('starts');
            $table->date('ends')->nullable();
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

            $table->foreign(['employee_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('employees')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['schedule_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('schedules')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['team_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('teams')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE rosters ADD CONSTRAINT rosters_dates_ordered CHECK (ends IS NULL OR ends >= starts)');

        // Inclusive ranges preserve one roster per employee per date, including open rosters.
        DB::statement("ALTER TABLE rosters ADD CONSTRAINT rosters_no_overlap EXCLUDE USING gist (employee_id WITH =, daterange(starts, ends, '[]') WITH &&)");

        // Team provenance does not constrain roster schedule or anchor, preserving history.
    }

    public function down(): void
    {
        Schema::dropIfExists('rosters');
    }
};
