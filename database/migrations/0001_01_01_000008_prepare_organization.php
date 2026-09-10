<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The organization tables' shared prerequisites: one extension and the
     * four functions `employees`, `workgroups` and `deployments` need. They live
     * here rather than in the table migrations because two of them outlive any
     * single table — `agency_not_platform()` is used by `employees` and
     * `workgroups` now and by `terminals` in Milestone 5, `string_set_valid()` is
     * the generic form of `permissions_valid()` and the next jsonb label set
     * will reuse it — so no table's own `down()` may drop them. The other two,
     * `workgroups_acyclic()` and `deployments_nested()`, back one table each and
     * live here to keep every function this milestone group creates behind one
     * `down()`.
     *
     * OR REPLACE, not a bare CREATE, on all four: `db:wipe` (what
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

        // A CHECK cannot see other rows, so "a workgroup is not under itself"
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
        // BEFORE (see 0001_01_01_000010_create_workgroups_table and
        // 07-constraints.md): several rows pointing at each other inside one
        // statement can close a cycle, and only a trigger firing after that
        // statement's rows are written sees them. By then the walk below
        // starts at a row that is in the table — the AFTER timing is what
        // makes this function's own recursive CTE meaningful on INSERT at all.
        // The self-parent INSERT is caught by the workgroups_parent_not_self CHECK,
        // which needs no walk.
        //
        // UNION, not UNION ALL: the recursive term then discards rows it has
        // already produced, so the walk terminates even against a cycle this
        // trigger did not create (an owner-role `ALTER TABLE ... DISABLE
        // TRIGGER`, a pre-trigger backup restored in). UNION ALL would spin.
        //
        // The drop first, and it is not housekeeping: this function was
        // `units_acyclic()` before decision 29, and `db:wipe` drops tables,
        // views and types but never functions. OR REPLACE only ever reaches
        // the *new* name, so without this line every database that ran the
        // pre-rename migration keeps a `units_acyclic()` referencing a table
        // that no longer exists, for good — invisible until something calls
        // it. New databases no-op on IF EXISTS.
        DB::statement('DROP FUNCTION IF EXISTS units_acyclic()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION workgroups_acyclic() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.parent_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF EXISTS (
                    WITH RECURSIVE ancestry (id, parent_id) AS (
                        SELECT workgroups.id, workgroups.parent_id
                          FROM workgroups
                         WHERE workgroups.id = NEW.parent_id
                        UNION
                        SELECT workgroups.id, workgroups.parent_id
                          FROM workgroups
                          JOIN ancestry ON workgroups.id = ancestry.parent_id
                    )
                    SELECT 1 FROM ancestry WHERE ancestry.id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'a workgroup cannot be placed under itself or its own descendant';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        // Decision 31's two nesting rules, the pair no CHECK can state because
        // each reads a row other than the one being written: a reassignment's
        // range sits inside the placement it departs from, and its parent is
        // itself substantive, so there is no reassignment under a
        // reassignment.
        //
        // Each rule is enforced from BOTH directions, and neither second
        // direction is redundant:
        //
        // - Containment checked only on the child is breakable by the one
        //   action that exists. TransferEmployee closes the open placement,
        //   and nothing would stop a reassignment outliving it. That is what
        //   the parent-side EXISTS below is for, and why 07-constraints.md's
        //   event list for this trigger includes `starts, ends` at all — a
        //   movement's own dates are already the child side's business.
        // The parent-side limbs run FIRST, before the `parent_id IS NULL`
        // early return, for that reason: a row that is *becoming* a movement
        // still has children to answer for.
        //
        // The two "parent must be substantive" limbs are DEPTH, not the
        // primary guard, and it is worth knowing which is which before
        // touching either. MEASURED by neutralising one RAISE at a time: only
        // the two containment limbs have a reachable violation. "No
        // reassignment under a reassignment" *follows* from the rest of the
        // schema — nesting requires containment, two movements of one
        // employee that contain one another necessarily overlap, and
        // deployments_no_overlapping_movements refuses overlapping movements
        // with 23P01 before this trigger is consulted. So every route to a
        // reassignment-under-a-reassignment is already closed, and these two
        // limbs only improve the message. They are kept for the same reason
        // workgroups keeps both workgroups_parent_not_self and
        // workgroups_acyclic. If deployments_no_overlapping_movements is ever
        // repartitioned or made deferrable, they stop being redundant and
        // become the only guard — which is the whole reason this note exists.
        //
        // AFTER, per-row, and a CONSTRAINT TRIGGER, for the reason
        // workgroups_acyclic is one (Ruling P12): a BEFORE ... FOR EACH ROW
        // trigger fires before its own row exists and cannot see the other
        // rows of its own statement, so a single multi-row INSERT of two
        // mutually-parented rows would close a cycle no limb ever ran
        // against. INITIALLY IMMEDIATE keeps it at end-of-statement rather
        // than commit, which is what leaves it catchable by
        // assertDatabaseRefuses(); INITIALLY DEFERRED would make it
        // vacuously green, as it will for turns_complete in Milestone 3.
        // 07-constraints.md said BEFORE, and was wrong for exactly the reason
        // it was wrong for workgroups.
        //
        // The parent lookup pairs on employee_id, not id alone, and stays
        // silent when it finds nothing — the same shape as
        // agency_not_platform() above. A parent_id naming another employee's
        // row must surface as 23503 from
        // deployments_parent_id_employee_id_foreign; if this function found
        // that row and raised P0001 on containment first, that FK's insert
        // side would have no reachable violation and no honest test.
        //
        // `placement record`, not `placement deployments`: a rowtype is
        // resolved when the function is compiled, and this migration runs
        // before the table it names exists.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION deployments_nested() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                placement record;
            BEGIN
                IF EXISTS (SELECT 1 FROM deployments movement WHERE movement.parent_id = NEW.id) THEN
                    IF NEW.parent_id IS NOT NULL THEN
                        RAISE EXCEPTION 'a placement with a reassignment under it cannot itself become one';
                    END IF;

                    IF EXISTS (
                        SELECT 1
                          FROM deployments movement
                         WHERE movement.parent_id = NEW.id
                           AND NOT daterange(NEW.starts, NEW.ends, '[]') @> daterange(movement.starts, movement.ends, '[]')
                    ) THEN
                        RAISE EXCEPTION 'a reassignment under this placement would fall outside it';
                    END IF;
                END IF;

                IF NEW.parent_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT deployments.parent_id, deployments.starts, deployments.ends
                  INTO placement
                  FROM deployments
                 WHERE deployments.id = NEW.parent_id
                   AND deployments.employee_id = NEW.employee_id;

                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;

                IF placement.parent_id IS NOT NULL THEN
                    RAISE EXCEPTION 'a reassignment cannot nest under another reassignment';
                END IF;

                IF NOT daterange(placement.starts, placement.ends, '[]') @> daterange(NEW.starts, NEW.ends, '[]') THEN
                    RAISE EXCEPTION 'a reassignment must sit inside the placement it departs from';
                END IF;

                RETURN NEW;
            END $$;
        SQL);
    }

    /**
     * The one place these functions are dropped: a table migration must not,
     * since `agency_not_platform()` and `string_set_valid()` are each shared
     * by more than one table. Argument types are required — dropping a
     * function by bare name is ambiguous.
     */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS deployments_nested()');
        DB::statement('DROP FUNCTION IF EXISTS workgroups_acyclic()');
        DB::statement('DROP FUNCTION IF EXISTS string_set_valid(jsonb)');
        DB::statement('DROP FUNCTION IF EXISTS agency_not_platform()');
    }
};
