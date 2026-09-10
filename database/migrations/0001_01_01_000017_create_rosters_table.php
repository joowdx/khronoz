<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An employee follows a schedule from `starts` to `ends`, with the cycle
     * anchored at `anchor` (docs/design/04-scheduling.md). This is the **only**
     * assignment: a direct FK, no polymorphism, and an exception is just a
     * roster of its own.
     *
     * One roster per employee per date, by the exclusion constraint below, and
     * that is what makes a one-week override a one-week roster: the constraint
     * forces the standing roster to be ended first, which is the right paper
     * trail (rule 3).
     *
     * No `agency_not_platform` trigger, for the reason `deployments` has none:
     * it would be unreachable. A roster needs an employee, and `employees`
     * refuses the platform agency already.
     *
     * Nothing here references `deployments`, and by decision 35 nothing ever
     * will — an employee's roster and their placement are independent facts,
     * and visibility reads the placements by overlap rather than through a key.
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

        // One roster per employee per date. '[]' for the same reason
        // deployments uses it — a roster ending 31 Jan and one starting 31 Jan
        // would both claim that day — and a null `ends` is an unbounded upper
        // bound, so "at most one open roster" comes free.
        //
        // Not partitioned, unlike deployments' pair: there is no nesting here.
        // A roster is the single answer to "what is this person expected to
        // work on this date", and an override replaces it for a period rather
        // than sitting inside it.
        DB::statement("ALTER TABLE rosters ADD CONSTRAINT rosters_no_overlap EXCLUDE USING gist (employee_id WITH =, daterange(starts, ends, '[]') WITH &&)");

        // Deliberately absent: any trigger holding a roster's schedule_id and
        // anchor equal to those of the team its team_id names. They **may**
        // diverge, and that is the design (07-constraints.md). team_id records
        // where the assignment came from, not a rule about what it produced,
        // so an agency can slide one nurse's anchor by a day without taking
        // her off the cohort — and re-anchoring a team re-issues rosters
        // rather than rewriting history. A consistency trigger would make
        // re-anchoring rewrite the past, which is the opposite of what the
        // ranges are for. Resolution reads the roster and never the team.
    }

    public function down(): void
    {
        Schema::dropIfExists('rosters');
    }
};
