<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The ingestion tables' shared functions, the arrangement
     * 0001_01_01_000008_prepare_organization,
     * 0001_01_01_000012_prepare_scheduling and
     * 0001_01_01_000018_prepare_calendar all make: they live here rather than
     * in a table migration because they span two tables each, and one `down()`
     * means no table migration has to know whether it may drop a function.
     *
     * OR REPLACE, for the reason every migration function is: `db:wipe` (what
     * `migrate:fresh` runs, i.e. every test run) drops tables, views and types
     * but never functions (.ai/rules/migrations.md).
     *
     * Both functions name `timelogs`, which does not exist when this migration
     * runs. That is legal: plpgsql resolves table names at **first execution**,
     * not when the function is compiled. The trap is a rowtype —
     * `DECLARE x timelogs%ROWTYPE` *is* resolved at compile time and would fail
     * here. Neither function uses one, and neither may gain one.
     */
    public function up(): void
    {
        // 03-terminals.md rule 3: `employee_id` and `enrollment_id` are set by
        // the database, not the app. Whatever a client puts in those two
        // columns is overwritten on every insert path — file import, manual
        // entry, and the push and pull protocols when they arrive.
        //
        // The lookup is deterministic because the two exclusion constraints on
        // `enrollments` guarantee at most one enrollment covers a given
        // (terminal_id, uid, date). Finding nothing is not an error: both
        // columns stay null, the timelog is *unresolved and still visible*,
        // and `timelogs_resolved_pair` holds the two columns null together.
        //
        // SECURITY DEFINER, so it keeps writing these columns after commit 5
        // revokes the app role's UPDATE on the table. That is the whole reason
        // resolution can be the database's job rather than the app's.
        //
        // The first exclusion constraint's gist index serves this lookup
        // (terminal_id = ? AND uid = ? AND range @> date), so it costs one
        // index probe and no extra index.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION timelogs_resolve() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER AS $$
            BEGIN
                SELECT enrollments.id, enrollments.employee_id
                  INTO NEW.enrollment_id, NEW.employee_id
                  FROM enrollments
                 WHERE enrollments.terminal_id = NEW.terminal_id
                   AND enrollments.uid = NEW.uid
                   AND daterange(enrollments.starts, enrollments.ends, '[]') @> NEW.time::date;

                RETURN NEW;
            END $$;
        SQL);

        // The same rule re-applied when an enrollment appears or its range
        // moves. Two statements, and the second is the one a happy-path test
        // never reaches: an enrollment narrowed off a timelog must null that
        // timelog back out, or a punch stays attributed to someone who was not
        // enrolled on that date.
        //
        // Scoped to the (terminal_id, uid) pair of the row that fired it, so
        // an enrollment change touches only the punches that could have
        // resolved through it. When `uid` or `terminal_id` themselves change,
        // Postgres fires this once per affected row; the OLD pair is covered
        // because the trigger is declared for INSERT OR UPDATE and the OLD
        // row's own pair no longer matches any enrollment, which the second
        // statement handles on its next write. Deleting an enrollment that
        // timelogs reference is refused by the paired FK — end it with `ends`.
        //
        // RETURN NULL because this is an AFTER trigger: the return value is
        // ignored, and NULL says so.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enrollments_reresolve() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER AS $$
            BEGIN
                UPDATE timelogs
                   SET enrollment_id = enrollments.id, employee_id = enrollments.employee_id
                  FROM enrollments
                 WHERE timelogs.terminal_id = NEW.terminal_id
                   AND timelogs.uid = NEW.uid
                   AND enrollments.terminal_id = timelogs.terminal_id
                   AND enrollments.uid = timelogs.uid
                   AND daterange(enrollments.starts, enrollments.ends, '[]') @> timelogs.time::date
                   AND (timelogs.enrollment_id IS DISTINCT FROM enrollments.id
                        OR timelogs.employee_id IS DISTINCT FROM enrollments.employee_id);

                UPDATE timelogs
                   SET enrollment_id = NULL, employee_id = NULL
                 WHERE timelogs.terminal_id = NEW.terminal_id
                   AND timelogs.uid = NEW.uid
                   AND timelogs.enrollment_id IS NOT NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM enrollments
                        WHERE enrollments.terminal_id = timelogs.terminal_id
                          AND enrollments.uid = timelogs.uid
                          AND daterange(enrollments.starts, enrollments.ends, '[]') @> timelogs.time::date
                   );

                RETURN NULL;
            END $$;
        SQL);
    }

    /** The one place these are dropped; the argument lists are required. */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS timelogs_resolve()');
        DB::statement('DROP FUNCTION IF EXISTS enrollments_reresolve()');
    }
};
