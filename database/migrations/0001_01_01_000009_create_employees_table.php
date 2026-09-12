<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employees precede workgroups so their paired key can constrain group heads.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->string('number');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->string('sex')->nullable();
            $table->date('birthdate')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('position')->nullable();
            $table->jsonb('tags')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('exempt')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // NOT NULL makes the agency-scoped unique number effective.
            $table->unique(['agency_id', 'number']);

            // This paired-key target prevents cross-agency employee references.
            $table->unique(['id', 'agency_id']);
        });

        // Keep this relationship with the employee schema it depends on,
        // rather than creating a standalone follow-up migration.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign(['employee_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('employees')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE employees
                ADD CONSTRAINT employees_sex_valid CHECK (sex IN ('male', 'female')),
                ADD CONSTRAINT employees_tags_valid CHECK (string_set_valid(tags)),
                ADD CONSTRAINT employees_tags_bounded CHECK (jsonb_typeof(tags) <> 'array' OR jsonb_array_length(tags) <= 20);
        SQL);

        // A type guard preserves this CHECK's SQLSTATE when JSON is not an array.

        // Deployments already require non-platform employee and workgroup parents.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agency_not_platform
                BEFORE INSERT OR UPDATE OF agency_id ON employees
                FOR EACH ROW EXECUTE FUNCTION agency_not_platform();
        SQL);

        // Tags are queried with the jsonb containment operators (`tags ? 'x'`,
        // `tags ?| array[...]`), which btree cannot serve.
        DB::statement('CREATE INDEX employees_tags ON employees USING gin (tags)');
    }

    /**
     * The trigger goes with the table and the index with it too; the shared
     * functions belong to 0001_01_01_000008_prepare_organization and are
     * dropped only there.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id', 'agency_id']);
        });

        Schema::dropIfExists('employees');
    }
};
