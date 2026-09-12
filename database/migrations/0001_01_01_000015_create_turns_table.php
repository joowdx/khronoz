<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paired keys prevent a turn from joining a schedule and shift from different agencies.
     */
    public function up(): void
    {
        Schema::create('turns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('schedule_id');
            $table->ulid('shift_id');
            // Uniqueness and this CHECK let the trigger enforce positions 0 through length - 1.
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

        // Deferred timing permits creating a schedule before its turns.
        // DELETE prevents gaps while allowing a missing parent during cascade.
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
