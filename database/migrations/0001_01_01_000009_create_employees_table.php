<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The people an agency keeps a DTR for (docs/design/01-organization.md).
     * Created after `users` so it can complete the user-to-employee pairing,
     * and before `workgroups`, whose head can pair to this table's
     * `UNIQUE (id, agency_id)` in its create migration.
     *
     * Every constraint the per-table block in docs/design/07-constraints.md
     * does not name is here anyway: `agency_id NOT NULL`, its FK, that FK's
     * explicit RESTRICT on both sides, and `UNIQUE (id, agency_id)` all come
     * from the file's "Defaults unless stated" sentence, and the `sex` CHECK
     * from the same sentence's rule that every enum is a varchar plus a CHECK
     * mirrored by a PHP enum (App\Enums\Sex).
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

            // The employee number is the agency's own, so it is unique per
            // agency and not globally. Both this and the pair below mean
            // nothing without `number` and `agency_id` being NOT NULL: nulls
            // are distinct in a unique index, so a nullable column would let
            // unlimited "duplicates" through.
            $table->unique(['agency_id', 'number']);

            // Trivially satisfied by the primary key, and the whole point of
            // the tenancy design: `workgroups.head_id`, `deployments.employee_id`
            // and `users.employee_id` each reference this pair, which is what
            // proves a child never points at an employee of another agency.
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

        // Postgres does not order CHECK evaluation, and jsonb_array_length()
        // raises 22023 on a non-array instead of returning false — so
        // employees_tags_bounded is guarded by its own typeof test. Without
        // the guard, inserting `'{}'::jsonb` could surface 22023 from the
        // bound rather than the 23514 that employees_tags_valid owes it,
        // depending on which constraint Postgres happens to check first.
        //
        // The bound is 20: tags are labels an agency edits by hand, not a
        // payload. A per-tag character bound is the Form Request's job; that
        // number is a UI decision that will change, and a CHECK is the wrong
        // place to keep a changing number.

        // Nothing operational hangs under the platform agency. `deployments`
        // deliberately has no such trigger: it needs an employee and a workgroup,
        // and both refuse the platform row already.
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
