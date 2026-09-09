<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The agency's formal structure: a tree of units, an employee placed in
     * exactly one at a time through `deployments`
     * (docs/design/01-organization.md).
     *
     * `kind` ("department", "division", "section", "office"...) is a plain
     * nullable string with no CHECK and no PHP enum, unlike every other
     * label-ish column in the schema: `01-organization.md` types it "label
     * only", and a later milestone matches it against a per-agency setting
     * string, which a fixed platform-wide enum would break.
     *
     * As with `employees`, the four constraints the per-table block in
     * docs/design/07-constraints.md does not name — `agency_id NOT NULL`, its
     * FK, that FK's explicit RESTRICT on both sides, `UNIQUE (id, agency_id)`
     * — come from the file's "Defaults unless stated" sentence and are here.
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
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

            // Paired, not plain: a plain FK on parent_id would prove the
            // parent exists, and this proves it belongs to the same agency.
            // The self-reference resolves against the UNIQUE (id, agency_id)
            // declared just above, in the same table.
            $table->foreign(['parent_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('units')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            // Deliberately not unique: one employee may head several units
            // (01-organization.md's "heads, one employee may head several").
            $table->foreign(['head_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('employees')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // IS DISTINCT FROM, not <>: parent_id is nullable and `NULL <> id` is
        // NULL, which a CHECK accepts.
        DB::statement('ALTER TABLE units ADD CONSTRAINT units_parent_not_self CHECK (parent_id IS DISTINCT FROM id)');

        // UPDATE OF parent_id, not INSERT alone: an INSERT can never close a
        // cycle, since a freshly generated ULID cannot already be an ancestor.
        // Repointing an existing row is the reachable violation — insert A,
        // insert B under A, then set A's parent to B — so an INSERT-only
        // trigger would be dead code that a self-parent test appears to cover
        // while really exercising units_parent_not_self above.
        //
        // INSERT is listed anyway: it costs one CTE that returns nothing for a
        // new id, and it closes the hole an explicitly-supplied id would open.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER units_acyclic
                BEFORE INSERT OR UPDATE OF parent_id ON units
                FOR EACH ROW EXECUTE FUNCTION units_acyclic();

            CREATE TRIGGER agency_not_platform
                BEFORE INSERT OR UPDATE OF agency_id ON units
                FOR EACH ROW EXECUTE FUNCTION agency_not_platform();
        SQL);
    }

    /**
     * Both triggers go with the table; their functions belong to
     * 0001_01_01_000007_prepare_organization and are dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
