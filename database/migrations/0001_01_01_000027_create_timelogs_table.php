<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Timelogs are immutable records; resolution overwrites client-supplied employee and enrollment values.
     */
    public function up(): void
    {
        Schema::create('timelogs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('terminal_id');
            // Nullable: a manual entry has no run. timelogs_source_pairs_sync
            // below ties this to `source` in both directions.
            $table->ulid('sync_id')->nullable();
            // Unresolved timelogs remain visible until enrollment resolution succeeds.
            $table->ulid('employee_id')->nullable();
            $table->ulid('enrollment_id')->nullable();
            // The device user id exactly as the attlog carries it — a string,
            // never integer-cast, never trimmed (decision 42).
            $table->string('uid');
            // Preserve the device's local clock precision for natural-key deduplication.
            $table->timestamp('time');
            // Preserve unknown raw device states without coercion.
            $table->unsignedTinyInteger('state');
            $table->unsignedTinyInteger('mode');
            $table->string('source');
            // Who entered it, manual only. A single-column FK: the person
            // doing data entry may be a platform superuser who has entered the
            // agency, whose own agency_id is the platform row.
            $table->ulid('user_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('reason')->nullable();
            // The voider is distinct from the original manual-entry actor.
            $table->ulid('voided_by')->nullable();
            // created_at only. See the docblock: `updated_at` would make
            // voiding through Eloquent fail 42501 once UPDATE is revoked.
            $table->timestamp('created_at')->nullable();

            $table->unique(['id', 'agency_id']);

            $table->foreign(['terminal_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('terminals')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            // The paired run key requires the same terminal; MATCH SIMPLE permits manual rows.
            $table->foreign(['sync_id', 'terminal_id'])
                ->references(['id', 'terminal_id'])
                ->on('syncs')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('voided_by')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // This natural key is the immutable import upsert target.
        DB::statement('ALTER TABLE timelogs ADD CONSTRAINT timelogs_attlog_key UNIQUE (terminal_id, uid, time, state, mode)');

        // Target for the punch FK. An unresolved timelog has a null
        // employee_id and so can never match a punch's non-null one — a punch
        // can only ever use a resolved timelog, by construction.
        DB::statement('ALTER TABLE timelogs ADD CONSTRAINT timelogs_id_employee_id_unique UNIQUE (id, employee_id)');

        // MATCH SIMPLE permits unresolved timelogs.
        // Deferred updates allow the re-resolution trigger to repair affected rows.
        DB::statement(<<<'SQL'
            ALTER TABLE timelogs ADD CONSTRAINT timelogs_enrollment_foreign
                FOREIGN KEY (enrollment_id, employee_id, terminal_id, uid)
                REFERENCES enrollments (id, employee_id, terminal_id, uid)
                ON DELETE RESTRICT ON UPDATE NO ACTION
                DEFERRABLE INITIALLY DEFERRED
        SQL);

        DB::statement("ALTER TABLE timelogs ADD CONSTRAINT timelogs_source_valid CHECK (source IN ('device', 'manual'))");

        // Resolution requires both employee and enrollment or neither.
        DB::statement('ALTER TABLE timelogs ADD CONSTRAINT timelogs_resolved_pair CHECK ((enrollment_id IS NULL) = (employee_id IS NULL))');

        // A device row must name the run that brought it in, and a manual row
        // must not. Both directions, in one CHECK.
        DB::statement("ALTER TABLE timelogs ADD CONSTRAINT timelogs_source_pairs_sync CHECK ((source = 'device') = (sync_id IS NOT NULL))");

        // Manual entries require an actor; device entries cannot name one.
        DB::statement("ALTER TABLE timelogs ADD CONSTRAINT timelogs_user_pairs_source CHECK ((source = 'manual') = (user_id IS NOT NULL))");

        // Voiding is the only correction this table allows, and an unexplained
        // void is worse than none: it removes a punch from the record with
        // nothing to audit.
        DB::statement('ALTER TABLE timelogs ADD CONSTRAINT timelogs_void_needs_reason CHECK (voided_at IS NULL OR reason IS NOT NULL)');

        // Voids require an actor and standing rows cannot name one.
        DB::statement('ALTER TABLE timelogs ADD CONSTRAINT timelogs_void_pairs_actor CHECK ((voided_at IS NULL) = (voided_by IS NULL))');

        // Postgres has no tinyint — Laravel's unsignedTinyInteger is a
        // smallint — so these bounds are the only thing keeping the raw attlog
        // ints inside the byte the device actually sends.
        DB::statement('ALTER TABLE timelogs ADD CONSTRAINT timelogs_state_valid CHECK (state BETWEEN 0 AND 255)');
        DB::statement('ALTER TABLE timelogs ADD CONSTRAINT timelogs_mode_valid CHECK (mode BETWEEN 0 AND 255)');

        // Resolution, on every insert path there will ever be: file import,
        // manual entry, push and pull. The function is in
        // 0001_01_01_000023_prepare_terminals.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER timelogs_resolve
                BEFORE INSERT ON timelogs
                FOR EACH ROW EXECUTE FUNCTION timelogs_resolve();
        SQL);

        // The trigger makes voids final because a CHECK cannot inspect OLD.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION timelogs_void_is_final() RETURNS trigger
                LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'timelog % was voided at % and cannot be changed', OLD.id, OLD.voided_at;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER timelogs_void_is_final
                BEFORE UPDATE ON timelogs
                FOR EACH ROW WHEN (OLD.voided_at IS NOT NULL)
                EXECUTE FUNCTION timelogs_void_is_final();
        SQL);

        // AppRoleGrants retains the restricted-update policy after db:grant.
        AppRoleGrants::restrict();

        // Attach re-resolution only after its timelogs target exists.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER enrollments_reresolve
                AFTER INSERT OR UPDATE OF starts, ends, employee_id, uid, terminal_id ON enrollments
                FOR EACH ROW EXECUTE FUNCTION enrollments_reresolve();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS enrollments_reresolve ON enrollments');

        Schema::dropIfExists('timelogs');

        // db:wipe never drops functions, which is why this one is CREATE OR
        // REPLACE — but a real rollback still cleans up (.ai/rules/migrations.md).
        DB::unprepared('DROP FUNCTION IF EXISTS timelogs_void_is_final()');
    }
};
