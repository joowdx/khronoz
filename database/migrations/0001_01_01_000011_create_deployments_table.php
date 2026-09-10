<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An employee's placement in a workgroup over a date range: the history of
     * where a person has worked, one substantive row at a time, with a
     * reassignment nested inside the placement it departs from
     * (docs/design/01-organization.md rules 2 and 7).
     *
     * `agency_id` is not redundant even though both parents carry one: it is
     * what makes the two FKs paired, so a deployment can never join an
     * employee of one agency to a workgroup of another. It carries the usual
     * `NOT NULL` and `UNIQUE (id, agency_id)` from
     * docs/design/07-constraints.md's "Defaults unless stated" sentence.
     *
     * No `agency_not_platform` trigger, unlike `employees` and `workgroups`: it
     * would be unreachable. A deployment needs an employee and a workgroup, and
     * both of those refuse the platform agency already.
     */
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('employee_id');
            $table->ulid('workgroup_id');
            // Null: this row is the employee's substantive placement, where
            // the plantilla item sits. Set: this row is a reassignment — the
            // person works elsewhere for a period while the substantive
            // placement stays open, because the item never left. There is
            // deliberately no type column; `parent_id IS NOT NULL` is the
            // whole fact (decision 31), and the API says the same thing with
            // a boolean rather than an id, because the parent is always the
            // employee's own open placement (decision 35).
            $table->ulid('parent_id')->nullable();
            $table->date('starts');
            $table->date('ends')->nullable();
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

            // Exists only so (parent_id, employee_id) below can pair against
            // it. Trivially satisfied — `id` is already unique — and it makes
            // "the parent is the same employee" structural rather than a
            // trigger, the same device the schema uses for (x_id, agency_id).
            $table->unique(['id', 'employee_id']);

            $table->foreign(['employee_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('employees')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['workgroup_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('workgroups')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            // RESTRICT on both sides, stated rather than inherited: Blueprint
            // does not default to it, and this is the one FK whose delete
            // side is load-bearing. Correcting a wrongly recorded deployment
            // is a DELETE and re-create (decision 35), never a soft delete
            // and never a PATCH, so RESTRICT is what makes that safe — a
            // placement with a reassignment nested under it cannot be deleted
            // until the reassignment goes first, refused with 23001 rather
            // than silently orphaning it.
            //
            // This is also the only foreign key in the schema pointing at
            // `deployments`, now and by decision 35 permanently: `ledgers`
            // refuses a deployment_id by decision 30, and rosters, workdays
            // and attestations reference it nowhere. Another incoming FK
            // would be a new decision, not an implementation detail.
            $table->foreign(['parent_id', 'employee_id'])
                ->references(['id', 'employee_id'])
                ->on('deployments')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // `starts` is NOT NULL for this CHECK's sake as much as its own: a
        // null `starts` makes `ends >= starts` evaluate to NULL, which a CHECK
        // accepts, and it also yields a daterange with an infinite lower bound
        // that the exclusion constraint below indexes perfectly happily.
        DB::statement('ALTER TABLE deployments ADD CONSTRAINT deployments_dates_ordered CHECK (ends IS NULL OR ends >= starts)');

        // The trivial half of "no reassignment under a reassignment", the
        // half that needs no cross-row walk. Declarative, so it is evaluated
        // before deployments_nested's AFTER trigger ever runs — which is why
        // a self-parent row answers 23514 and not P0001. Exactly the division
        // of labour between workgroups_parent_not_self and workgroups_acyclic.
        DB::statement('ALTER TABLE deployments ADD CONSTRAINT deployments_parent_not_self CHECK (parent_id IS DISTINCT FROM id)');

        // Two partial exclusions on the same key, not one total one
        // (decision 31). Each forbids overlap *within its own class*, so an
        // employee has at most one substantive placement and at most one
        // reassignment per date, and at most one open row of each. A
        // reassignment may overlap the substantive placement it departs from —
        // that nesting is the entire point — but never another reassignment,
        // since nobody is detailed to two places at once.
        //
        // Partitioned on `parent_id IS NULL` and never on a label:
        // partitioning by a movement's *kind* would let a "detail" and a
        // "reassignment" overlap each other, which is wrong, and is half the
        // reason there is no type column.
        //
        // '[]' — inclusive upper bound, not Postgres's default '[)'. The
        // observable difference: a deployment ending 31 Jan and one starting
        // 31 Jan conflict, which is right, since the employee is in both workgroups
        // that day. A null `ends` is an unbounded upper bound, so two open
        // ranges of one class always overlap; that is where "at most one open"
        // comes from, with no separate constraint.
        //
        // NOT deferrable, deliberately, however much TransferEmployee would
        // like to close one row and open the next inside one transaction. The
        // project's assertDatabaseRefuses() helper runs each statement in a
        // SAVEPOINT, and releasing a SAVEPOINT does not run deferred checks —
        // they fire at the outer COMMIT, which a test never reaches — so
        // DEFERRABLE INITIALLY DEFERRED would leave both of these uncatchable
        // and vacuously green.
        //
        // deployments_no_overlapping_movements carries a second job nothing
        // names it for: it is what actually forbids a reassignment under a
        // reassignment. Nesting requires containment, containment between two
        // movements of one employee means overlap, and this constraint
        // refuses that overlap before deployments_nested is consulted — so
        // the trigger's two "parent must be substantive" limbs are depth
        // rather than the guard. Repartitioning this constraint, or making it
        // deferrable, promotes those limbs to load-bearing.
        DB::statement("ALTER TABLE deployments ADD CONSTRAINT deployments_no_overlap EXCLUDE USING gist (employee_id WITH =, daterange(starts, ends, '[]') WITH &&) WHERE (parent_id IS NULL)");
        DB::statement("ALTER TABLE deployments ADD CONSTRAINT deployments_no_overlapping_movements EXCLUDE USING gist (employee_id WITH =, daterange(starts, ends, '[]') WITH &&) WHERE (parent_id IS NOT NULL)");

        // The two cross-row rules the constraints above cannot state, each
        // enforced from both directions. The function, the AFTER/CONSTRAINT
        // timing and the reasoning for every limb are in
        // 0001_01_01_000008_prepare_organization; the trigger itself goes with
        // the table, since Postgres drops a trigger along with the table it
        // is on.
        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER deployments_nested
                AFTER INSERT OR UPDATE OF parent_id, starts, ends ON deployments
                DEFERRABLE INITIALLY IMMEDIATE
                FOR EACH ROW EXECUTE FUNCTION deployments_nested();
        SQL);
    }

    /**
     * The trigger goes with the table; `deployments_nested()` belongs to
     * 0001_01_01_000008_prepare_organization and is dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
