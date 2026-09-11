<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the device recorded (docs/design/03-terminals.md). Never a punch —
     * a punch is one matched slot side of a workday and arrives in M6.
     *
     * **A timelog is immutable.** Nothing is ever pruned; a bad one gets
     * `voided_at` and `reason` and stays. That is enforced by privilege rather
     * than by trigger, and the REVOKEs land in the next commit. The predecessor
     * had *four* independent ways to destroy one of these rows — a flush verb
     * that hard-deleted, a `Prunable` scheduled every minute, and cascading
     * deletes from both the scanner and the self-FK — which is why every
     * foreign key here is RESTRICT and none of those verbs exists.
     *
     * There is a `created_at` and deliberately **no `updated_at`**, and that is
     * load-bearing rather than tidy: once the next commit revokes UPDATE down
     * to `(voided_at, reason)`, an Eloquent write that also touched
     * `updated_at` would fail with 42501. Voiding through the model works only
     * because the column is not there. The predecessor had neither, and so
     * could not say when a punch was ingested at all.
     *
     * `employee_id` and `enrollment_id` are **never written by the
     * application**. `timelogs_resolve` fills them from the one enrollment
     * covering (terminal_id, uid, time::date), or leaves both null, and
     * overwrites whatever a client sent. The paired four-column FK is a second
     * lock on that: satisfied by construction today, it catches a future bug
     * in the function.
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
            // Null means **unresolved**, and an unresolved timelog stays
            // visible. It is not an error and never rejected: a punch by
            // somebody not yet enrolled is real data, and hiding it is how a
            // day silently goes missing.
            $table->ulid('employee_id')->nullable();
            $table->ulid('enrollment_id')->nullable();
            // The device user id exactly as the attlog carries it — a string,
            // never integer-cast, never trimmed (decision 42).
            $table->string('uid');
            // As reported by the device: a naive local wall clock, never
            // converted to UTC and never adjusted for observed drift
            // (rule 4). Third column of the natural key below, so its
            // precision decides whether re-importing one file dedupes.
            $table->timestamp('time');
            // Raw attlog integers, not enums (rule 6). An unknown value from
            // unfamiliar firmware must survive; the model casts with tryFrom
            // and never writes an interpretation back.
            $table->unsignedTinyInteger('state');
            $table->unsignedTinyInteger('mode');
            $table->string('source');
            // Who entered it, manual only. A single-column FK: the person
            // doing data entry may be a platform superuser who has entered the
            // agency, whose own agency_id is the platform row.
            $table->ulid('user_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('reason')->nullable();
            // created_at only. See the docblock: `updated_at` would make
            // voiding through Eloquent fail 42501 once UPDATE is revoked.
            $table->timestamp('created_at')->nullable();

            $table->unique(['id', 'agency_id']);

            $table->foreign(['terminal_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('terminals')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('sync_id')->references('id')->on('syncs')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        // **The attlog natural key**, and the upsert target (rule 2). Import is
        // INSERT ... ON CONFLICT DO NOTHING on exactly these five columns:
        // inserted rows are `accepted`, skipped rows `duplicates`. All five are
        // NOT NULL, so NULLS DISTINCT cannot quietly let a duplicate through.
        //
        // This is the one thing the predecessor got right and then undid in the
        // writer: it declared the same key and then issued ON CONFLICT DO
        // UPDATE against it, rewriting each row with itself — which made the
        // row mutable, made "how many are new" unknowable, and raised 21000
        // whenever one statement carried the same key twice.
        DB::statement('ALTER TABLE timelogs ADD CONSTRAINT timelogs_attlog_key UNIQUE (terminal_id, uid, time, state, mode)');

        // Target for the punch FK in M6. An unresolved timelog has a null
        // employee_id and so can never match a punch's non-null one — a punch
        // can only ever use a resolved timelog, by construction.
        DB::statement('ALTER TABLE timelogs ADD CONSTRAINT timelogs_id_employee_id_unique UNIQUE (id, employee_id)');

        // The second lock on resolution: a timelog may only name an enrollment
        // that agrees with it about the employee, the terminal and the device
        // user id. MATCH SIMPLE skips it entirely while enrollment_id is null,
        // which is what lets an unresolved row exist at all.
        //
        // The two sides are deliberately asymmetric, and it took a failing
        // test to notice why they have to be.
        //
        // **Delete stays RESTRICT**, and immediate: an enrollment with punches
        // hanging off it is ended with `ends`, never removed. Postgres keeps a
        // RESTRICT check immediate even on a DEFERRABLE constraint, so that
        // refusal still arrives as 23001 at the statement — verified against
        // the database rather than assumed.
        //
        // **Update is NO ACTION DEFERRABLE INITIALLY DEFERRED**, because an
        // enrollment's `uid`, `terminal_id` and `employee_id` must be able to
        // change — a mistyped device user id is an ordinary correction, and
        // `enrollments_reresolve` is declared `UPDATE OF ... employee_id, uid,
        // terminal_id` precisely to handle it. Under ON UPDATE RESTRICT that
        // trigger could never fire: the FK refused the parent's own UPDATE
        // before the trigger got to repair the children, so the design
        // contradicted itself and the first test to try it failed with 23001.
        // Deferring the check to commit lets the AFTER trigger re-resolve the
        // affected punches first; the constraint then re-checks and finds them
        // consistent.
        //
        // The cost is that an insert-side violation surfaces at COMMIT rather
        // than at the statement, so a test provoking one needs
        // `SET CONSTRAINTS ALL IMMEDIATE`. Acceptable: this FK is defence in
        // depth against a future bug in the resolver, not a rule the
        // application is expected to hit.
        DB::statement(<<<'SQL'
            ALTER TABLE timelogs ADD CONSTRAINT timelogs_enrollment_foreign
                FOREIGN KEY (enrollment_id, employee_id, terminal_id, uid)
                REFERENCES enrollments (id, employee_id, terminal_id, uid)
                ON DELETE RESTRICT ON UPDATE NO ACTION
                DEFERRABLE INITIALLY DEFERRED
        SQL);

        DB::statement("ALTER TABLE timelogs ADD CONSTRAINT timelogs_source_valid CHECK (source IN ('device', 'manual'))");

        // Resolved means **both** columns or neither. Half-resolved is not a
        // state: employee_id without enrollment_id is an attribution nothing
        // can justify, and enrollment_id without employee_id would satisfy the
        // paired FK vacuously.
        DB::statement('ALTER TABLE timelogs ADD CONSTRAINT timelogs_resolved_pair CHECK ((enrollment_id IS NULL) = (employee_id IS NULL))');

        // A device row must name the run that brought it in, and a manual row
        // must not. Both directions, in one CHECK.
        DB::statement("ALTER TABLE timelogs ADD CONSTRAINT timelogs_source_pairs_sync CHECK ((source = 'device') = (sync_id IS NOT NULL))");

        // MC 21 s. 1991: a manually entered time record must say who recorded
        // it.
        DB::statement("ALTER TABLE timelogs ADD CONSTRAINT timelogs_manual_needs_user CHECK (source <> 'manual' OR user_id IS NOT NULL)");

        // Voiding is the only correction this table allows, and an unexplained
        // void is worse than none: it removes a punch from the record with
        // nothing to audit.
        DB::statement('ALTER TABLE timelogs ADD CONSTRAINT timelogs_void_needs_reason CHECK (voided_at IS NULL OR reason IS NOT NULL)');

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

        // And the re-resolution trigger on `enrollments`, created **here**
        // rather than in that table's own migration: it writes to `timelogs`,
        // so attaching it earlier would make the next enrollment inserted
        // answer 42P01 and break every seeder and factory in between.
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
    }
};
