<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-owned shifts are defaults; agency copies retain their origin.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name');
            // An empty slot array is an Off shift, not a missing value.
            $table->jsonb('slots')->default('[]');
            // Credited minutes are policy, not a slot-derived duration.
            $table->smallInteger('required');
            // Arrival band in minutes, 0 = fixed. Under MC 06 s. 2022
            // flexitime, arriving 83 minutes late slides every expected time
            // by 83 rather than making the day tardy, capped at this value.
            $table->smallInteger('flex')->default(0);
            // No punches expected; the day is credited on attestation and
            // never yields overtime (Flexiplace, OP MC 114).
            $table->boolean('remote')->default(false);
            // Whether the device's own in/out state is truth or merely a
            // hint when matching a timelog to a slot (06-attendance.md).
            $table->boolean('trust')->default(false);
            // The application assigns this fixed roster-grid colour index.
            $table->smallInteger('color');
            // This reference-only platform origin may be removed without blocking copies.
            $table->ulid('origin_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'agency_id']);
            $table->unique(['agency_id', 'name']);
        });

        // Add the self-reference after creation because Blueprint otherwise emits it before its key.
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_origin_id_foreign FOREIGN KEY (origin_id) REFERENCES shifts (id) ON DELETE SET NULL ON UPDATE RESTRICT');

        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_required_not_negative CHECK (required >= 0)');
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_flex_not_negative CHECK (flex >= 0)');
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_color_in_ramp CHECK (color BETWEEN 1 AND 8)');
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_slots_valid CHECK (slots_valid(slots))');

        // Each type guard preserves CHECK SQLSTATE 23514 for non-array JSON.
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_remote_has_no_slots CHECK (jsonb_typeof(slots) <> 'array' OR NOT remote OR jsonb_array_length(slots) = 0)");
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_off_credits_nothing CHECK (jsonb_typeof(slots) <> 'array' OR jsonb_array_length(slots) > 0 OR remote OR required = 0)");
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_off_has_no_flex CHECK (jsonb_typeof(slots) <> 'array' OR jsonb_array_length(slots) > 0 OR flex = 0)");

        // origin_id must point at a platform-owned row, which no FK can say;
        // the function and its reasoning are in
        // 0001_01_01_000012_prepare_scheduling.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER origin_is_platform
                BEFORE INSERT OR UPDATE OF origin_id ON shifts
                FOR EACH ROW EXECUTE FUNCTION origin_is_platform();
        SQL);
    }

    /**
     * The trigger goes with the table; `origin_is_platform()` and
     * `slots_valid()` belong to 0001_01_01_000012_prepare_scheduling and are
     * dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
