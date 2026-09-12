<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cadences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name');
            $table->string('kind');
            $table->jsonb('rules')->default(DB::raw("'{}'::jsonb"));
            $table->date('anchor')->nullable();
            $table->boolean('preferred')->default(false);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'agency_id']);
            $table->unique(['agency_id', 'name']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE cadences
                ADD CONSTRAINT cadences_kind_valid CHECK (kind IN ('weekly', 'fortnightly', 'semimonthly', 'monthly')),
                ADD CONSTRAINT cadences_rules_object CHECK (jsonb_typeof(rules) = 'object'),
                ADD CONSTRAINT cadences_anchor_valid CHECK ((kind IN ('weekly', 'fortnightly')) = (anchor IS NOT NULL)),
                ADD CONSTRAINT cadences_preferred_active CHECK (NOT preferred OR retired_at IS NULL),
                ADD CONSTRAINT cadences_starts_valid CHECK (
                    CASE
                        WHEN kind IN ('weekly', 'fortnightly') THEN NOT (rules ? 'starts')
                        WHEN NOT (rules ? 'starts') THEN true
                        WHEN jsonb_typeof(rules->'starts') <> 'array' THEN false
                        WHEN kind = 'monthly' THEN
                            jsonb_array_length(rules->'starts') = 1
                            AND jsonb_typeof(rules->'starts'->0) = 'number'
                            AND (rules->'starts'->>0)::numeric BETWEEN 1 AND 28
                            AND (rules->'starts'->>0)::numeric = trunc((rules->'starts'->>0)::numeric)
                        WHEN kind = 'semimonthly' THEN
                            jsonb_array_length(rules->'starts') = 2
                            AND jsonb_typeof(rules->'starts'->0) = 'number'
                            AND jsonb_typeof(rules->'starts'->1) = 'number'
                            AND (rules->'starts'->>0)::numeric BETWEEN 1 AND 28
                            AND (rules->'starts'->>1)::numeric BETWEEN 1 AND 28
                            AND (rules->'starts'->>0)::numeric = trunc((rules->'starts'->>0)::numeric)
                            AND (rules->'starts'->>1)::numeric = trunc((rules->'starts'->>1)::numeric)
                            AND (rules->'starts'->>0)::numeric < (rules->'starts'->>1)::numeric
                        ELSE false
                    END
                );
            CREATE UNIQUE INDEX cadences_one_preferred ON cadences (agency_id) WHERE preferred;
        SQL);
        Schema::table('employees', function (Blueprint $table) {
            $table->foreign(['cadence_id', 'agency_id'])->references(['id', 'agency_id'])->on('cadences')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->dropForeign(['cadence_id', 'agency_id']));
        Schema::dropIfExists('cadences');
    }
};
