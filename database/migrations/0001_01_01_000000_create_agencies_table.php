<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tenant table. Exactly one row carries `platform = true`; it owns the
     * shared rows (national holidays, default shifts and schedules, superusers)
     * in place of a null agency_id, so every later table can declare agency_id
     * NOT NULL (docs/design/07-constraints.md). The partial unique index below
     * enforces "at most one such row"; the trigger below that stops the flag
     * from moving and the row from being deleted once it is set.
     */
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('platform')->default(false);
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX agencies_platform ON agencies (platform) WHERE platform');
        DB::statement("ALTER TABLE agencies ADD CONSTRAINT agencies_settings_object CHECK (jsonb_typeof(settings) = 'object')");

        // A CHECK constraint cannot compare NEW against OLD, so "platform cannot
        // change after insert" needs a trigger; the DELETE branch rides the same
        // function since both guard the platform row for the same reason.
        //
        // OR REPLACE, not a bare CREATE: migrate:fresh drops agencies (and with
        // it, this trigger) via db:wipe, but db:wipe only drops tables, views and
        // (on Postgres, opt-in) types — never functions — so a plain CREATE
        // FUNCTION would collide with itself on the second migrate:fresh against
        // the same database, which is exactly what every test run after the
        // first does.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION agencies_platform_row() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.platform THEN
                        RAISE EXCEPTION 'the platform agency cannot be deleted';
                    END IF;
                    RETURN OLD;
                END IF;
                IF NEW.platform IS DISTINCT FROM OLD.platform THEN
                    RAISE EXCEPTION 'platform cannot change after insert';
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER agencies_platform_row
                BEFORE UPDATE OF platform OR DELETE ON agencies
                FOR EACH ROW EXECUTE FUNCTION agencies_platform_row();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('agencies');
        DB::statement('DROP FUNCTION IF EXISTS agencies_platform_row()');
    }
};
