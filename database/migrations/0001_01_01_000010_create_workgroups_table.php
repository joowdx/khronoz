<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `kind` remains agency-defined because head resolution uses each agency's vocabulary.
     */
    public function up(): void
    {
        Schema::create('workgroups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('parent_id')->nullable();
            $table->string('kind')->nullable();
            $table->string('code');
            $table->string('name');
            $table->ulid('head_id')->nullable();
            $table->timestamps();

            $table->unique(['agency_id', 'code']);
            $table->unique(['id', 'agency_id']);

            // The paired key requires a parent from the same agency.
            $table->foreign(['parent_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('workgroups')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            // Deliberately not unique: one employee may head several workgroups
            // (01-organization.md's "heads, one employee may head several").
            $table->foreign(['head_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('employees')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // IS DISTINCT FROM, not <>: parent_id is nullable and `NULL <> id` is
        // NULL, which a CHECK accepts.
        DB::statement('ALTER TABLE workgroups ADD CONSTRAINT workgroups_parent_not_self CHECK (parent_id IS DISTINCT FROM id)');

        // AFTER sees multi-row insert cycles; UPDATE covers reparenting.
        // Initially immediate timing keeps refusals statement-local.
        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER workgroups_acyclic
                AFTER INSERT OR UPDATE OF parent_id ON workgroups
                DEFERRABLE INITIALLY IMMEDIATE
                FOR EACH ROW EXECUTE FUNCTION workgroups_acyclic();

            CREATE TRIGGER agency_not_platform
                BEFORE INSERT OR UPDATE OF agency_id ON workgroups
                FOR EACH ROW EXECUTE FUNCTION agency_not_platform();
        SQL);
    }

    /**
     * Both triggers go with the table; their functions belong to
     * 0001_01_01_000008_prepare_organization and are dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('workgroups');
    }
};
