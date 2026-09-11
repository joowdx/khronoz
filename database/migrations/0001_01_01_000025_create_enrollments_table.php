<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which employee is which device user id, and when
     * (docs/design/03-terminals.md).
     *
     * This is the table that makes a punch belong to a person, and it is a
     * **date range** rather than a flag. The predecessor made it
     * `UNIQUE (employee_id, scanner_id)` plus an `active` boolean, which says
     * an employee has at most one enrollment per device *for all time* — so a
     * device user id reissued to a new hire, or a re-enrolment after a device
     * reset, could only be recorded by editing the existing row and destroying
     * the history that every historical punch resolves through. "Who was UID
     * 42 on 2026-03-15" was unanswerable. Here it has exactly one answer, and
     * the two exclusion constraints below are why.
     *
     * No `active` column and no denormalised device code: the range *is* the
     * activity, and the predecessor kept a duplicate `device` column in sync
     * with two `saved()` model hooks — one extra UPDATE and one lazy load per
     * write, and a silent resolution failure whenever they drifted.
     *
     * No `agency_not_platform` trigger, for the reason `deployments` has none:
     * it needs an employee and a terminal, and both refuse the platform row
     * already.
     */
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('employee_id');
            $table->ulid('terminal_id');
            // The device user id **as the attlog carries it** — a string, never
            // integer-cast (decision 42). `007` and `7` are two people, and
            // `A17` is a legal id on some firmwares that `int()` cannot hold.
            // No accessor trims this: the resolution trigger joins on the raw
            // column, so a trim applied in PHP and not in SQL produces rows
            // that look matched and never resolve.
            $table->string('uid');
            $table->string('privilege');
            $table->date('starts');
            // Open upper bound — the `deployments`/`rosters` convention, and
            // the opposite of `exemptions.until` (decision 38). Null means the
            // enrollment is current, and `daterange(starts, ends, '[]')` with a
            // null upper bound is unbounded above, which is exactly what is
            // wanted here.
            $table->date('ends')->nullable();
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

            $table->foreign(['employee_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('employees')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['terminal_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('terminals')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // The four-column target for `timelogs`' paired FK. It is what makes
        // that FK a second lock on resolution rather than a formality: a
        // timelog may only name an enrollment that agrees with it about the
        // employee, the terminal *and* the device user id, so a future bug in
        // timelogs_resolve() surfaces as 23503 instead of a mis-attributed
        // punch.
        DB::statement('ALTER TABLE enrollments ADD CONSTRAINT enrollments_resolution_key UNIQUE (id, employee_id, terminal_id, uid)');

        DB::statement("ALTER TABLE enrollments ADD CONSTRAINT enrollments_privilege_valid CHECK (privilege IN ('user', 'enroller', 'admin', 'superadmin'))");

        DB::statement('ALTER TABLE enrollments ADD CONSTRAINT enrollments_dates_ordered CHECK (ends IS NULL OR ends >= starts)');

        // **A device user id is one person at a time.** Without this,
        // timelogs_resolve()'s lookup is not deterministic — two enrollments
        // covering one date would make "who punched" depend on which row the
        // planner happened to return. Every punch this device ever recorded
        // under that uid resolves through whichever enrollment covers its
        // date, so the constraint is what lets the trigger be a plain SELECT
        // with no ordering and no LIMIT.
        //
        // Its gist index also serves that lookup
        // (terminal_id = ? AND uid = ? AND range @> date), so resolution costs
        // one probe and no extra index.
        DB::statement(<<<'SQL'
            ALTER TABLE enrollments ADD CONSTRAINT enrollments_uid_one_person
                EXCLUDE USING gist (
                    terminal_id WITH =,
                    uid WITH =,
                    daterange(starts, ends, '[]') WITH &&
                )
        SQL);

        // **And a person holds one device user id at a time, per device.** The
        // mirror of the rule above, and not implied by it: nothing in the first
        // constraint stops one employee being enrolled twice on one terminal
        // under two different uids on the same day, which would make their
        // punches split across two identities and each half look like an
        // incomplete day.
        //
        // Both are scoped to a terminal, so the same person may hold a
        // different uid on every device they use — which is the normal case,
        // since each device numbers its own users.
        DB::statement(<<<'SQL'
            ALTER TABLE enrollments ADD CONSTRAINT enrollments_one_uid_per_person
                EXCLUDE USING gist (
                    employee_id WITH =,
                    terminal_id WITH =,
                    daterange(starts, ends, '[]') WITH &&
                )
        SQL);

        // The `enrollments_reresolve` trigger belongs on this table but is
        // created by `0001_01_01_000027_create_timelogs_table`, and that is a
        // dependency fact rather than a preference. Creating the *function*
        // before `timelogs` exists is fine — plpgsql resolves table names at
        // first execution. Attaching the *trigger* here is not: the very next
        // enrollment inserted would fire it and answer 42P01, and every seeder,
        // factory and test between this migration and the timelogs one would
        // fail. It is attached where the table it writes to is created.
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
