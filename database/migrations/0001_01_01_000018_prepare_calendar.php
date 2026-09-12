<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The shared actor function lives here so table rollbacks do not drop it.
     */
    public function up(): void
    {
        // A trigger permits the row agency or platform actor; no FK can express that choice.
        // Missing actors and agencies remain silent for FK 23503 and NOT NULL 23502.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION actor_of_agency() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                actor record;
            BEGIN
                IF NEW.agency_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT users.agency_id, agencies.platform
                  INTO actor
                  FROM users JOIN agencies ON agencies.id = users.agency_id
                 WHERE users.id = NEW.user_id;

                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;

                IF actor.platform OR actor.agency_id = NEW.agency_id THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'the recording user must belong to this agency or be a platform user';
            END $$;
        SQL);
    }

    /**
     * This is the sole owner of the function; its argument list disambiguates the drop.
     */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS actor_of_agency()');
    }
};
