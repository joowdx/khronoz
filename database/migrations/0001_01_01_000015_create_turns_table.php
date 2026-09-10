<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One day of a schedule's cycle: which shift sits at which position
     * (docs/design/04-scheduling.md). A 21-day hospital rotation is 21 rows.
     *
     * `agency_id` is not redundant even though both parents carry one: it is
     * what makes the two FKs paired, so a turn can never put a shift of one
     * agency into a schedule of another. Same reasoning as `deployments`.
     */
    public function up(): void
    {
        Schema::create('turns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('schedule_id');
            $table->ulid('shift_id');
            // 0 to length - 1. UNIQUE (schedule_id, position) and this
            // CHECK are two thirds of "the turns must be complete": with no
            // duplicates and no negatives, turns_complete only has to prove
            // the count and the maximum to force exactly the set 0..length-1.
            $table->smallInteger('position');
            $table->timestamps();

            $table->unique(['id', 'agency_id']);
            $table->unique(['schedule_id', 'position']);

            $table->foreign(['schedule_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('schedules')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['shift_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('shifts')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE turns ADD CONSTRAINT turns_position_not_negative CHECK (position >= 0)');

        // Exactly `length` turns at positions 0 to length - 1
        // (04-scheduling.md rule 4). DEFERRABLE INITIALLY DEFERRED — the only
        // deferred constraint in the schema — because the rule is transiently
        // false by construction: a schedule is written before its turns
        // exist, so an immediate check could never let one be created at all.
        //
        // DELETE is listed as well as INSERT and UPDATE, so removing a turn
        // from a complete set is refused rather than quietly leaving a gap
        // the resolver would read as a missing position. Dropping a schedule
        // with its turns still works: the function returns early when the
        // schedule is gone.
        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER turns_complete
                AFTER INSERT OR UPDATE OR DELETE ON turns
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION turns_complete();
        SQL);
    }

    /**
     * The trigger goes with the table; `turns_complete()` belongs to
     * 0001_01_01_000012_prepare_scheduling and is dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('turns');
    }
};
