<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A named `(schedule, anchor)` cohort (docs/design/04-scheduling.md).
     * Three hospital teams are one schedule and three anchors, sitting seven
     * positions apart so every shift is covered.
     *
     * **No `starts`/`ends`**: a team is a standing definition, not an
     * arrangement with a date range. And **no membership table**: the rosters
     * carrying a team's `team_id` *are* its membership, so who was on it in
     * March is answerable from their own date ranges, and one employee on two
     * teams at once is already impossible because one roster covers a date.
     *
     * `agency_not_platform`, unlike `shifts` and `schedules`: default shifts
     * and schedules are exactly what the platform agency is for, but a team
     * is a cohort of real people and nothing operational hangs under the
     * platform row (07-constraints.md).
     *
     * A team is deliberately not a `Workgroup` and the two must not be
     * merged (.ai/rules/models.md): a workgroup is where a person is
     * *placed*, one at a time, through a Deployment; a team is the rotation
     * they are *rostered into*.
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name');
            $table->ulid('schedule_id');
            // Cycle day 0 for the whole cohort. Re-anchoring a team re-issues
            // its members' rosters rather than updating them in place, so a
            // past rotation stays answerable — which is also why a roster
            // keeps its own copy of this and may diverge from it.
            $table->date('anchor');
            $table->timestamps();

            $table->unique(['id', 'agency_id']);
            $table->unique(['agency_id', 'name']);

            $table->foreign(['schedule_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('schedules')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agency_not_platform
                BEFORE INSERT OR UPDATE OF agency_id ON teams
                FOR EACH ROW EXECUTE FUNCTION agency_not_platform();
        SQL);
    }

    /**
     * The trigger goes with the table; `agency_not_platform()` is shared with
     * `employees` and `workgroups` and belongs to
     * 0001_01_01_000008_prepare_organization.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
