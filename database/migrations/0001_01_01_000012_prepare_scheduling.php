<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shared functions live here so table rollbacks do not drop one another's dependencies.
     */
    public function up(): void
    {
        // Filtered parsing preserves the CHECK's SQLSTATE for malformed slot values.
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

        // The trigger permits only platform origins; missing parents remain silent for FK SQLSTATE 23503.
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

        // Deferral permits transiently incomplete turn sets within one transaction.
        // TG_OP branching avoids unavailable NEW fields on DELETE and schedule triggers.
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
