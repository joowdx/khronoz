<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rosters provide dated membership; teams are not workgroups.
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name');
            $table->ulid('schedule_id');
            // Rosters retain their anchor so re-anchoring preserves history.
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
