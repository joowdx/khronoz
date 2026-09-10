<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A day template (docs/design/04-scheduling.md, "The five words"): the
     * expected in/out pairs, what a complete day credits, and how much the
     * arrival may slide. `Off` is a shift with no slots; `Remote` is a shift
     * with no slots and `remote` true.
     *
     * No `agency_not_platform` trigger, unlike `employees`, `workgroups` and
     * `teams`: the platform agency is exactly where the *default* shifts live
     * (07-constraints.md, "Global rows belong to the platform agency"), and an
     * agency's own shift is a copy carrying `origin_id` back to the platform
     * row it came from.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name');
            // Ordered in/out pairs; shape enforced by slots_valid(). The
            // empty array is `Off` and is the column default, which is why
            // this is NOT NULL rather than nullable — "no slots" is a real
            // shift, not a missing value.
            $table->jsonb('slots')->default('[]');
            // Minutes a complete day credits. Not derived from the slots: an
            // agency may credit a 24-hour duty as 8 hours (04-scheduling.md's
            // Civil Security Unit example), so the number is a policy and the
            // slots are a timetable.
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
            // The roster grid's fixed eight-colour ramp index, stored and
            // never derived from the name or the id (04-scheduling.md rule 8,
            // decision 21). A new shift takes the lowest index its agency is
            // not using and wraps at 8 — that choice is the application's,
            // but the range is the database's.
            $table->smallInteger('color');
            // The platform row this was copied from: the one deliberate
            // cross-agency pointer in the schema, reference-only, so the UI
            // can show a copy has diverged and offer to refresh it. SET NULL
            // rather than RESTRICT because losing the ancestry of a copy is
            // not a reason to keep a default row alive.
            $table->ulid('origin_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'agency_id']);
            $table->unique(['agency_id', 'name']);
        });

        // Added after Schema::create and not inside it: Blueprint emits a
        // self-referencing foreign key as an ALTER ahead of the statement
        // that establishes the primary key it references, so declaring it
        // inline fails with 42830, "there is no unique constraint matching
        // given keys". `deployments`' own self-FK escapes this only because
        // it targets a composite UNIQUE declared before it.
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_origin_id_foreign FOREIGN KEY (origin_id) REFERENCES shifts (id) ON DELETE SET NULL ON UPDATE RESTRICT');

        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_required_not_negative CHECK (required >= 0)');
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_flex_not_negative CHECK (flex >= 0)');
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_color_in_ramp CHECK (color BETWEEN 1 AND 8)');
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_slots_valid CHECK (slots_valid(slots))');

        // The three rules that relate `slots` to the other columns, each
        // carrying its own `jsonb_typeof(slots) <> 'array' OR` guard for the
        // reason employees_tags_bounded does: Postgres does not order CHECK
        // evaluation, and jsonb_array_length() raises 22023 on a non-array
        // rather than returning false, so an unguarded one could surface
        // 22023 where these owe 23514.
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
