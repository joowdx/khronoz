<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The calendar tables' shared function, the same arrangement
     * 0001_01_01_000008_prepare_organization and
     * 0001_01_01_000012_prepare_scheduling make: it lives here rather than in
     * a table migration because three tables use it, and one `down()` means
     * no table migration has to know whether it may drop a function.
     *
     * OR REPLACE, for the reason every migration function is: `db:wipe` (what
     * `migrate:fresh` runs, i.e. every test run) drops tables, views and types
     * but never functions (.ai/rules/migrations.md).
     */
    public function up(): void
    {
        // `suspensions`, `exemptions` and `overtimes` each record **who**
        // declared, entered or approved the row, and each does so with a
        // single-column FK to `users` rather than the paired
        // `(user_id, agency_id)` this schema uses everywhere else — including
        // on `attestations`, which does pair, because a signature must come
        // from inside the agency.
        //
        // The reason is real: a platform superuser who has *entered* an agency
        // does the data entry for it, and their own agency_id is the platform
        // row, so a paired FK would refuse exactly the person doing the work.
        // But an unpaired FK is wider than that intent — it also accepts an
        // ordinary user of some *third* agency, which nothing should ever
        // record. An adversarial review on 2026-09-11 named that gap, and
        // 07-constraints.md's own opening claim ("the database is the only
        // place a rule cannot be bypassed") is what makes it worth closing:
        // the application cannot produce such a row, but the application is
        // one client among several.
        //
        // No foreign key can say "this agency **or** the platform one", so a
        // trigger does — exactly the division of labour `origin_is_platform()`
        // makes for the other deliberate cross-agency pointer in the schema.
        //
        // Silent on **two** inputs, both for the same reason
        // `agency_not_platform()` and `origin_is_platform()` are silent on a
        // missing row: another constraint owes the refusal, and raising P0001
        // first would shadow it and leave that constraint with no reachable
        // violation and no honest test.
        //
        // A user that does not exist: the FK owes 23503. And a **null
        // agency_id**: the NOT NULL owes 23502. The second was found by the
        // existing `*_needs_an_agency` tests, which started answering P0001
        // the moment this trigger was attached — a BEFORE ROW trigger runs
        // ahead of the column constraints, and `actor.agency_id = NULL` is
        // NULL rather than true, so every one of them fell through to the
        // RAISE.
        //
        // One function for three tables, driven by NEW.user_id and
        // NEW.agency_id, which every one of them carries — so unlike
        // `origin_is_platform()` it needs no dynamic SQL.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION actor_of_agency() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                actor record;
            BEGIN
                IF NEW.agency_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT users.agency_id, agencies.platform
                  INTO actor
                  FROM users JOIN agencies ON agencies.id = users.agency_id
                 WHERE users.id = NEW.user_id;

                IF NOT FOUND THEN
                    RETURN NEW;
                END IF;

                IF actor.platform OR actor.agency_id = NEW.agency_id THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'the recording user must belong to this agency or be a platform user';
            END $$;
        SQL);
    }

    /** The one place this is dropped; the argument list is required. */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS actor_of_agency()');
    }
};
