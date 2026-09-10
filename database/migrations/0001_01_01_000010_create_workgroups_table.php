<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The agency's formal structure: a tree of workgroups, an employee placed in
     * exactly one at a time through `deployments`
     * (docs/design/01-organization.md).
     *
     * `kind` ("department", "division", "section", "unit", "office"...) is a
     * plain nullable string with no CHECK and no PHP enum, unlike every other
     * label-ish column in the schema: `01-organization.md` types it "label
     * only", and 06-attendance.md's attestation chain resolves `head` as the
     * nearest ancestor *of the kind an agency setting names*, so the
     * vocabulary has to stay the agency's. "unit" is in that list on purpose:
     * it is a legitimate kind, which is precisely why the container it sits
     * in is called a workgroup and not a unit (decision 29).
     *
     * As with `employees`, the four constraints the per-table block in
     * docs/design/07-constraints.md does not name — `agency_id NOT NULL`, its
     * FK, that FK's explicit RESTRICT on both sides, `UNIQUE (id, agency_id)`
     * — come from the file's "Defaults unless stated" sentence and are here.
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

            // Paired, not plain: a plain FK on parent_id would prove the
            // parent exists, and this proves it belongs to the same agency.
            // The self-reference resolves against the UNIQUE (id, agency_id)
            // declared just above, in the same table.
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

        // UPDATE OF parent_id, not INSERT alone: a single-row INSERT can never
        // close a cycle, since a freshly generated ULID cannot already be an
        // ancestor. Repointing an existing row is the everyday violation —
        // insert A, insert B under A, then set A's parent to B — so an
        // INSERT-only trigger would be dead code that a self-parent test
        // appears to cover while really exercising workgroups_parent_not_self above.
        //
        // INSERT is listed anyway, because a multi-row INSERT can close a
        // cycle within one statement: several rows pointing at each other
        // arrive together, and no one of them is a cycle on its own. Catching
        // that is what AFTER buys — a BEFORE ... FOR EACH ROW trigger fires
        // before its own row exists and cannot see the statement's other rows,
        // while an AFTER row trigger fires once those rows are written and the
        // walk sees all of them (07-constraints.md). The CONSTRAINT form is
        // what makes the timing settable at all, and INITIALLY IMMEDIATE keeps
        // it at end-of-statement rather than commit; the sibling visibility
        // comes from AFTER, not from constraint-ness.
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
