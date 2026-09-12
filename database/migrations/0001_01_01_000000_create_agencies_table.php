<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The immutable platform row owns global records without nullable tenant keys.
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

        // Enumerated JSON values avoid casts that could replace this CHECK's SQLSTATE.
        DB::statement(<<<'SQL'
            ALTER TABLE agencies ADD CONSTRAINT agencies_rest_day_after_bounded CHECK (
                settings -> 'rest_day_after' IS NULL
                OR settings -> 'rest_day_after' = 'null'::jsonb
                OR settings -> 'rest_day_after' IN ('1'::jsonb, '2'::jsonb, '3'::jsonb, '4'::jsonb, '5'::jsonb, '6'::jsonb)
            )
        SQL);

        // A trigger compares OLD and NEW; OR REPLACE is required because db:wipe retains functions.
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
