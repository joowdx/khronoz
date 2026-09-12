<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-owned schedules are defaults; agency copies retain their origin.
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name');
            // A rotation is bounded to one year; a single-turn cycle is valid.
            $table->smallInteger('length');
            // The paired key keeps the fallback shift within the agency.
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

        // INSERT prevents a schedule without turns from escaping validation.
        // Deferred timing permits creating or resizing a schedule with its turns in one transaction.
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
