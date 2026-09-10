<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The scheduling tables' shared functions, the same arrangement
     * 0001_01_01_000008_prepare_organization makes for the organization ones:
     * they live here rather than in the table migrations because two of the
     * three are shared, and keeping all three behind one `down()` means no
     * table migration has to know whether it may drop a function.
     *
     * OR REPLACE on all three: `db:wipe` (what `migrate:fresh` runs, i.e.
     * every test run) drops tables, views and types but never functions, so a
     * plain CREATE FUNCTION collides with itself on the second `migrate:fresh`
     * against the same database (.ai/rules/migrations.md).
     */
    public function up(): void
    {
        // The slot shape, checked by the database and not only by a Form
        // Request, because the workday deriver reads these pairs directly and
        // a malformed one is a wrong DTR rather than a validation slip
        // (docs/design/04-scheduling.md, "Slot shape").
        //
        // Times are 'HH:MM' with hours past 24 rolling into following days,
        // like a transit timetable: '30:00' is 06:00 the next day, '56:00' is
        // 08:00 two days on, capped at 72:00. That is what replaces an
        // `overnight` flag, and it is why the hour is matched as one or two
        // digits rather than bounded to 23.
        //
        // The WHERE clause in the CTE is what makes this total rather than
        // raising: a malformed element is simply not parsed, and the
        // `jsonb_array_length(slots) = count(*)` line then fails because one
        // element went missing. Without that, `split_part(...)::int` on a
        // non-numeric string would raise 22P02 where this CHECK owes 23514.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION slots_valid(slots jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
                WITH s AS (
                    SELECT e AS slot, i,
                           split_part(e->>'in',  ':', 1)::int * 60 + split_part(e->>'in',  ':', 2)::int AS t_in,
                           split_part(e->>'out', ':', 1)::int * 60 + split_part(e->>'out', ':', 2)::int AS t_out
                      FROM jsonb_array_elements(CASE WHEN jsonb_typeof(slots) = 'array' THEN slots ELSE '[]'::jsonb END)
                           WITH ORDINALITY AS t(e, i)
                     WHERE jsonb_typeof(e) = 'object'
                       AND e->>'in'  ~ '^\d{1,2}:[0-5]\d$'
                       AND e->>'out' ~ '^\d{1,2}:[0-5]\d$'
                       AND jsonb_typeof(e->'window') = 'array' AND jsonb_array_length(e->'window') = 2
                       AND (e->'window'->>0) ~ '^-?\d+$'
                       AND (e->'window'->>1) ~ '^-?\d+$'
                       AND (e->>'grace' IS NULL OR e->>'grace' ~ '^-?\d+$')
                )
                SELECT jsonb_typeof(slots) = 'array'
                   AND jsonb_array_length(CASE WHEN jsonb_typeof(slots) = 'array' THEN slots ELSE '[]'::jsonb END)
                       = (SELECT count(*) FROM s)
                   AND NOT EXISTS (SELECT 1 FROM s
                                    WHERE t_in >= t_out OR t_out > 72 * 60
                                       OR coalesce((slot->>'grace')::int, 0) < 0
                                       OR (slot->'window'->>0)::int > 0
                                       OR (slot->'window'->>1)::int < 0)
                   AND NOT EXISTS (SELECT 1 FROM s a JOIN s b ON b.i = a.i + 1 WHERE b.t_in < a.t_out);
            $$;
        SQL);

        // A copy's `origin_id` is the one deliberate cross-agency pointer in
        // the schema (07-constraints.md, "Global rows belong to the platform
        // agency"), and it exists so the UI can show that a copy has diverged
        // from the default it came from and offer to refresh it. It is
        // reference-only, which is why it is a plain single-column FK rather
        // than a paired one — the pair is exactly what it is allowed to break.
        //
        // What must still hold is that it points at a *platform* row and not
        // at another agency's private shift, or the pointer would become a
        // cross-tenant read. No FK can say that, so this trigger does.
        //
        // Dynamic SQL because `shifts.origin_id` points into `shifts` and
        // `schedules.origin_id` into `schedules`: TG_TABLE_NAME is the table
        // in both cases, so one function serves both rather than two
        // near-identical ones.
        //
        // Silent when the origin does not exist at all, for the reason
        // agency_not_platform() is (…000008): the FK owes 23503 there, and
        // raising P0001 first would shadow it and leave it untestable.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION origin_is_platform() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                owned_by_platform boolean;
            BEGIN
                IF NEW.origin_id IS NULL THEN
                    RETURN NEW;
                END IF;

                EXECUTE format(
                    'SELECT agencies.platform
                       FROM %I origin
                       JOIN agencies ON agencies.id = origin.agency_id
                      WHERE origin.id = $1',
                    TG_TABLE_NAME
                ) INTO owned_by_platform USING NEW.origin_id;

                IF owned_by_platform IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NOT owned_by_platform THEN
                    RAISE EXCEPTION 'an origin must be a platform-owned %', TG_TABLE_NAME;
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        // A schedule's turns must be complete: exactly `length` rows, at
        // positions 0 to length - 1 (04-scheduling.md rule 4). Neither half
        // can be a CHECK — both count rows of another table — and the rule is
        // *transiently false* by construction, since a schedule is written
        // before its turns exist and a length change is written before the
        // turns are adjusted.
        //
        // Hence DEFERRABLE INITIALLY DEFERRED, the only constraint in the
        // schema that is: it is checked at COMMIT, so one transaction may
        // insert a schedule and its seven turns in any order. Note what that
        // costs in testing — assertDatabaseRefuses() runs each statement in a
        // SAVEPOINT, and releasing a SAVEPOINT does not run deferred checks,
        // so a test must either `SET CONSTRAINTS ALL IMMEDIATE` inside the
        // closure or commit for real. A test that does neither passes
        // vacuously.
        //
        // UNIQUE (schedule_id, position) and CHECK (position >= 0) carry the
        // rest of the rule declaratively, so this function only has to prove
        // the count and the maximum: with no duplicates and no negatives,
        // `count(*) = length AND max(position) = length - 1` forces exactly
        // the set 0..length-1.
        //
        // It fires for both tables, so it reads the schedule id from
        // whichever side triggered it, and tolerates the schedule having been
        // deleted in the same transaction — deleting a schedule and its turns
        // together must not raise on the turns' own DELETE.
        //
        // Branches and not a CASE expression, and TG_OP and not COALESCE over
        // NEW/OLD, both learned by running it: plpgsql resolves the field
        // references in *every* arm of a CASE, so `NEW.schedule_id` raises
        // 42703 on the `schedules` table where no such field exists; and NEW
        // is unassigned in an AFTER DELETE trigger, so touching it there
        // raises rather than yielding null for COALESCE to absorb. The
        // variable is `target` rather than `schedule_id` so it cannot shadow
        // the column of that name in the query below.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION turns_complete() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                target char(26);
                expected smallint;
                present smallint;
                highest smallint;
            BEGIN
                IF TG_TABLE_NAME = 'schedules' THEN
                    target := NEW.id;
                ELSIF TG_OP = 'DELETE' THEN
                    target := OLD.schedule_id;
                ELSE
                    target := NEW.schedule_id;
                END IF;

                SELECT schedules.length INTO expected FROM schedules WHERE schedules.id = target;

                IF expected IS NULL THEN
                    RETURN NULL;
                END IF;

                SELECT count(*), max(turns.position) INTO present, highest
                  FROM turns WHERE turns.schedule_id = target;

                IF present <> expected OR COALESCE(highest, -1) <> expected - 1 THEN
                    RAISE EXCEPTION 'a schedule of length % needs turns at positions 0 to %, and has % of them',
                        expected, expected - 1, present;
                END IF;

                RETURN NULL;
            END $$;
        SQL);
    }

    /**
     * The one place these three are dropped; argument types are required,
     * since dropping a function by bare name is ambiguous.
     */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS turns_complete()');
        DB::statement('DROP FUNCTION IF EXISTS origin_is_platform()');
        DB::statement('DROP FUNCTION IF EXISTS slots_valid(jsonb)');
    }
};
