<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The organization tables' shared prerequisites: one extension and the
     * three functions `employees`, `units` and `deployments` need. They live
     * here rather than in the table migrations because two of them outlive any
     * single table — `agency_not_platform()` is used by `employees` and
     * `units` now and by `terminals` in Milestone 5, `string_set_valid()` is
     * the generic form of `permissions_valid()` and the next jsonb label set
     * will reuse it — so no table's own `down()` may drop them.
     *
     * OR REPLACE, not a bare CREATE, on all three: `db:wipe` (what
     * `migrate:fresh` runs, i.e. every test run) drops tables, views and types
     * but never functions, so a plain CREATE FUNCTION collides with itself on
     * the second `migrate:fresh` against the same database
     * (.ai/rules/migrations.md).
     */
    public function up(): void
    {
        // Already present on the live cluster (1.8) and re-applied by
        // AppRoleGrants::apply(); still declared here because a fresh database
        // has neither, and `deployments`' exclusion constraint mixes `=` on a
        // char(26) with `&&` on a daterange, which needs btree_gist's operator
        // classes in one gist index.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // Nothing operational hangs under the platform agency
        // (docs/design/07-constraints.md, "Global rows belong to the platform
        // agency"). The trigger fires BEFORE INSERT, ahead of the agency_id
        // foreign key, so it must stay quiet when the agency does not exist at
        // all: `IF (SELECT platform ...)` yields NULL there, NULL is not true,
        // and the FK then raises its own 23503. Raising on "not found" instead
        // would shadow the FK with P0001 and leave it untested on every table
        // that carries this trigger.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION agency_not_platform() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT platform FROM agencies WHERE id = NEW.agency_id) THEN
                    RAISE EXCEPTION 'the platform agency has no %', TG_TABLE_NAME;
                END IF;
                RETURN NEW;
            END $$;
        SQL);

        // The shape `permissions_valid()` proves for users.permissions, minus
        // the column name: a jsonb array of distinct, non-empty strings. The
        // empty array is legal — it is employees.tags' own default. How many
        // elements are allowed is the caller's CHECK, not this function's, so
        // the next label set can reuse it with its own bound.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION string_set_valid(value jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
                SELECT jsonb_typeof(value) = 'array'
                   AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(value) e WHERE jsonb_typeof(e) <> 'string')
                   AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(value) e WHERE e = '')
                   AND jsonb_array_length(value) = (SELECT count(DISTINCT e) FROM jsonb_array_elements_text(value) e);
            $$;
        SQL);

        // A CHECK cannot see other rows, so "a unit is not under itself"
        // (transitively) needs a trigger walking parent_id upward.
        //
        // It matters that this fires on UPDATE OF parent_id and not only on
        // INSERT: a *single-row* INSERT cannot close a cycle, because a
        // freshly generated ULID cannot already be an ancestor of anything, so
        // the everyday violation is repointing an existing row — insert A,
        // insert B under A, then set A's parent to B — and an INSERT-only
        // trigger would be near enough dead code.
        //
        // A multi-row INSERT is the exception, and it is why the trigger this
        // function backs is AFTER and per-row on both events rather than
        // BEFORE (see 0001_01_01_000009_create_units_table and
        // 07-constraints.md): several rows pointing at each other inside one
        // statement can close a cycle, and only a trigger firing after that
        // statement's rows are written sees them. By then the walk below
        // starts at a row that is in the table — the AFTER timing is what
        // makes this function's own recursive CTE meaningful on INSERT at all.
        // The self-parent INSERT is caught by the units_parent_not_self CHECK,
        // which needs no walk.
        //
        // UNION, not UNION ALL: the recursive term then discards rows it has
        // already produced, so the walk terminates even against a cycle this
        // trigger did not create (an owner-role `ALTER TABLE ... DISABLE
        // TRIGGER`, a pre-trigger backup restored in). UNION ALL would spin.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION units_acyclic() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.parent_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF EXISTS (
                    WITH RECURSIVE ancestry (id, parent_id) AS (
                        SELECT units.id, units.parent_id
                          FROM units
                         WHERE units.id = NEW.parent_id
                        UNION
                        SELECT units.id, units.parent_id
                          FROM units
                          JOIN ancestry ON units.id = ancestry.parent_id
                    )
                    SELECT 1 FROM ancestry WHERE ancestry.id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'a unit cannot be placed under itself or its own descendant';
                END IF;

                RETURN NEW;
            END $$;
        SQL);
    }

    /**
     * The one place the three functions are dropped: a table migration must
     * not, since `agency_not_platform()` and `string_set_valid()` are each
     * shared by more than one table. Argument types are required — dropping a
     * function by bare name is ambiguous.
     */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS units_acyclic()');
        DB::statement('DROP FUNCTION IF EXISTS string_set_valid(jsonb)');
        DB::statement('DROP FUNCTION IF EXISTS agency_not_platform()');
    }
};
