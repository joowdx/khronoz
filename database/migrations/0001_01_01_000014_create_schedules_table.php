<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A repeating cycle of shifts (docs/design/04-scheduling.md): `length`
     * days, one turn per day, resolved as
     * `position = (D - roster.anchor) mod length`.
     *
     * Like `shifts` and unlike `teams`, a schedule may belong to the platform
     * agency — that is where the defaults live, and an agency's own schedule
     * is a copy carrying `origin_id` back to it.
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name');
            // Days in the cycle. Bounded at 366 rather than left open: the
            // cycle is a rotation, and anything longer than a year is a
            // calendar, which is what holidays and exemptions are for. 1 is
            // legal — a single-turn schedule is every day the same.
            $table->smallInteger('length');
            // When a holiday or suspension lands on an Off turn, the other
            // turns of that ISO week resolve to this shift instead, so a
            // compressed week reverts to standard days rather than losing the
            // holiday (Res. 2600838 §2.3, 04-scheduling.md rule 5). Paired FK
            // — a fallback must be a shift of the same agency.
            $table->ulid('fallback_shift_id')->nullable();
            // The platform row this was copied from; reference only, see
            // shifts.origin_id for why it is deliberately unpaired.
            $table->ulid('origin_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'agency_id']);
            $table->unique(['agency_id', 'name']);

            $table->foreign(['fallback_shift_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('shifts')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // After Schema::create for the reason shifts' own origin FK is: a
        // self-referencing key declared inline is emitted before the primary
        // key it references exists (42830).
        DB::statement('ALTER TABLE schedules ADD CONSTRAINT schedules_origin_id_foreign FOREIGN KEY (origin_id) REFERENCES schedules (id) ON DELETE SET NULL ON UPDATE RESTRICT');

        DB::statement('ALTER TABLE schedules ADD CONSTRAINT schedules_length_bounded CHECK (length BETWEEN 1 AND 366)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER origin_is_platform
                BEFORE INSERT OR UPDATE OF origin_id ON schedules
                FOR EACH ROW EXECUTE FUNCTION origin_is_platform();
        SQL);

        // The other half of turns_complete: changing a schedule's length
        // invalidates a turn set that was complete a moment ago, so the
        // constraint has to watch this column as well as the turns
        // themselves. DEFERRED, so one transaction may widen the length and
        // add the turns in either order — the function and the testing
        // consequences are in 0001_01_01_000012_prepare_scheduling.
        //
        // INSERT is listed as well as UPDATE OF length, which 07-constraints.md
        // did not have, and it is not belt-and-braces. MEASURED before adding
        // it: a schedule created with no turns at all was accepted and then
        // never checked again, because nothing had changed on `turns` and the
        // length had never been updated — so an unresolvable schedule could
        // sit in the table permanently, and the resolver would find no turn at
        // any position. Deferral is what makes covering INSERT free: a real
        // create writes the schedule and its turns in one transaction, so the
        // check still sees a complete set at COMMIT.
        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER turns_complete
                AFTER INSERT OR UPDATE OF length ON schedules
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION turns_complete();
        SQL);
    }

    /**
     * Both triggers go with the table; their functions belong to
     * 0001_01_01_000012_prepare_scheduling and are dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
