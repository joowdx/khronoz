<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An employee's placement in a workgroup over a date range: the history of
     * where a person has worked, one row at a time
     * (docs/design/01-organization.md rule 2).
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
            $table->date('starts');
            $table->date('ends')->nullable();
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

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
        });

        // `starts` is NOT NULL for this CHECK's sake as much as its own: a
        // null `starts` makes `ends >= starts` evaluate to NULL, which a CHECK
        // accepts, and it also yields a daterange with an infinite lower bound
        // that the exclusion constraint below indexes perfectly happily.
        DB::statement('ALTER TABLE deployments ADD CONSTRAINT deployments_dates_ordered CHECK (ends IS NULL OR ends >= starts)');

        // No two deployments of one employee may overlap, which also gives "at
        // most one open deployment" for free: a null `ends` is an unbounded
        // upper bound, so two open ranges always overlap.
        //
        // '[]' — inclusive upper bound, not Postgres's default '[)'. The
        // observable difference: a deployment ending 31 Jan and one starting
        // 31 Jan conflict, which is right, since the employee is in both workgroups
        // that day.
        //
        // NOT deferrable, deliberately, however much a later MoveEmployee
        // action would like to close one row and open the next inside one
        // transaction. The project's assertDatabaseRefuses() helper runs each
        // statement in a SAVEPOINT, and releasing a SAVEPOINT does not run
        // deferred checks — they fire at the outer COMMIT, which a test never
        // reaches — so DEFERRABLE INITIALLY DEFERRED would make this
        // constraint's test uncatchable and vacuously green.
        DB::statement("ALTER TABLE deployments ADD CONSTRAINT deployments_no_overlap EXCLUDE USING gist (employee_id WITH =, daterange(starts, ends, '[]') WITH &&)");
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
