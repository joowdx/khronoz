<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shared functions avoid future-table rowtypes because those resolve before timelogs exists.
     */
    public function up(): void
    {
        // `FOR SHARE` prevents stale attribution during enrollment edits.
        // SECURITY DEFINER preserves resolution after app-role updates are revoked.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION timelogs_resolve() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                SELECT enrollments.id, enrollments.employee_id
                  INTO NEW.enrollment_id, NEW.employee_id
                  FROM public.enrollments
                 WHERE enrollments.terminal_id = NEW.terminal_id
                   AND enrollments.uid = NEW.uid
                   AND daterange(enrollments.starts, enrollments.ends, '[]') @> NEW.time::date
                   FOR SHARE;

                RETURN NEW;
            END $$;
        SQL);

        // Re-resolve both NEW and OLD pairs: device-reported timelog UIDs are never rewritten.
        // Attach covered timelogs, then detach those no enrollment covers.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enrollments_reresolve() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                pair record;
            BEGIN
                FOR pair IN
                    SELECT DISTINCT candidate.terminal_id, candidate.uid
                      FROM (VALUES
                                (NEW.terminal_id, NEW.uid),
                                (CASE WHEN TG_OP = 'UPDATE' THEN OLD.terminal_id END,
                                 CASE WHEN TG_OP = 'UPDATE' THEN OLD.uid END)
                           ) AS candidate(terminal_id, uid)
                     WHERE candidate.terminal_id IS NOT NULL
                       AND candidate.uid IS NOT NULL
                LOOP
                    UPDATE public.timelogs
                       SET enrollment_id = enrollments.id, employee_id = enrollments.employee_id
                      FROM public.enrollments
                     WHERE timelogs.terminal_id = pair.terminal_id
                       AND timelogs.uid = pair.uid
                       AND enrollments.terminal_id = timelogs.terminal_id
                       AND enrollments.uid = timelogs.uid
                       AND daterange(enrollments.starts, enrollments.ends, '[]') @> timelogs.time::date
                       AND (timelogs.enrollment_id IS DISTINCT FROM enrollments.id
                            OR timelogs.employee_id IS DISTINCT FROM enrollments.employee_id);

                    UPDATE public.timelogs
                       SET enrollment_id = NULL, employee_id = NULL
                     WHERE timelogs.terminal_id = pair.terminal_id
                       AND timelogs.uid = pair.uid
                       AND timelogs.enrollment_id IS NOT NULL
                       AND NOT EXISTS (
                           SELECT 1 FROM public.enrollments
                            WHERE enrollments.terminal_id = timelogs.terminal_id
                              AND enrollments.uid = timelogs.uid
                              AND daterange(enrollments.starts, enrollments.ends, '[]') @> timelogs.time::date
                       );
                END LOOP;

                RETURN NULL;
            END $$;
        SQL);
    }

    /**
     * This is the sole owner of the functions; their argument lists disambiguate the drops.
     */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS timelogs_resolve()');
        DB::statement('DROP FUNCTION IF EXISTS enrollments_reresolve()');
    }
};
