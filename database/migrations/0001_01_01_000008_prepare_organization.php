<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shared functions live here so table rollbacks never drop another table's function.
     */
    public function up(): void
    {
        // Required for the deployment exclusion constraint's mixed GiST operators.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // A missing agency remains silent so its foreign key owns SQLSTATE 23503.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION agency_not_platform() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT platform FROM agencies WHERE id = NEW.agency_id) THEN
                    RAISE EXCEPTION 'the platform agency has no %', TG_TABLE_NAME;
                END IF;
                RETURN NEW;
            END $$;
        SQL);

        // Callers impose cardinality; this reusable check accepts distinct, non-empty strings.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION string_set_valid(value jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
                SELECT jsonb_typeof(value) = 'array'
                   AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(value) e WHERE jsonb_typeof(e) <> 'string')
                   AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(value) e WHERE e = '')
                   AND jsonb_array_length(value) = (SELECT count(DISTINCT e) FROM jsonb_array_elements_text(value) e);
            $$;
        SQL);

        // AFTER handles cycles created by multi-row inserts; updates cover the ordinary reparenting case.
        // UNION terminates even for a pre-existing cycle.
        // Drop the renamed legacy function because db:wipe retains functions.
        DB::statement('DROP FUNCTION IF EXISTS units_acyclic()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION workgroups_acyclic() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.parent_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF EXISTS (
                    WITH RECURSIVE ancestry (id, parent_id) AS (
                        SELECT workgroups.id, workgroups.parent_id
                          FROM workgroups
                         WHERE workgroups.id = NEW.parent_id
                        UNION
                        SELECT workgroups.id, workgroups.parent_id
                          FROM workgroups
                          JOIN ancestry ON workgroups.id = ancestry.parent_id
                    )
                    SELECT 1 FROM ancestry WHERE ancestry.id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'a workgroup cannot be placed under itself or its own descendant';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        // AFTER timing sees multi-row writes while remaining statement-local.
        // Use `record` because future-table rowtypes resolve too early.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION deployments_nested() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                placement record;
            BEGIN
                IF EXISTS (SELECT 1 FROM deployments movement WHERE movement.parent_id = NEW.id) THEN
                    IF NEW.parent_id IS NOT NULL THEN
                        RAISE EXCEPTION 'a placement with a reassignment under it cannot itself become one';
                    END IF;

                    IF EXISTS (
                        SELECT 1
                          FROM deployments movement
                         WHERE movement.parent_id = NEW.id
                           AND NOT daterange(NEW.starts, NEW.ends, '[]') @> daterange(movement.starts, movement.ends, '[]')
                    ) THEN
                        RAISE EXCEPTION 'a reassignment under this placement would fall outside it';
                    END IF;
                END IF;

                IF NEW.parent_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT deployments.parent_id, deployments.starts, deployments.ends
                  INTO placement
                  FROM deployments
                 WHERE deployments.id = NEW.parent_id
                   AND deployments.employee_id = NEW.employee_id;

                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;

                IF placement.parent_id IS NOT NULL THEN
                    RAISE EXCEPTION 'a reassignment cannot nest under another reassignment';
                END IF;

                IF NOT daterange(placement.starts, placement.ends, '[]') @> daterange(NEW.starts, NEW.ends, '[]') THEN
                    RAISE EXCEPTION 'a reassignment must sit inside the placement it departs from';
                END IF;

                RETURN NEW;
            END $$;
        SQL);
    }

    /**
     * Shared functions are dropped only here; argument types disambiguate the drops.
     */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS deployments_nested()');
        DB::statement('DROP FUNCTION IF EXISTS workgroups_acyclic()');
        DB::statement('DROP FUNCTION IF EXISTS string_set_valid(jsonb)');
        DB::statement('DROP FUNCTION IF EXISTS agency_not_platform()');
    }
};
