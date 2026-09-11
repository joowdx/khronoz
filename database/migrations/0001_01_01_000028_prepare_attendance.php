<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The attendance tables' shared functions, the same arrangement
     * 0001_01_01_000008_prepare_organization,
     * 0001_01_01_000012_prepare_scheduling,
     * 0001_01_01_000018_prepare_calendar and
     * 0001_01_01_000023_prepare_terminals all make: they live here rather
     * than in a table migration because they span more than one table, and
     * one `down()` means no table migration has to know whether it may drop
     * a function.
     *
     * OR REPLACE, for the reason every migration function is: `db:wipe` (what
     * `migrate:fresh` runs, i.e. every test run) drops tables, views and types
     * but never functions (.ai/rules/migrations.md).
     *
     * All five name tables that do not exist when this migration runs. That
     * is legal: plpgsql resolves table names at **first execution**, not when
     * the function is compiled. The trap is a rowtype — `DECLARE x ledgers`
     * *is* resolved at compile time and would fail here. None of these uses
     * one, and none may gain one.
     *
     * Three of the five are attached later (`punches_timelog_live` on
     * punches, `attestations_locked` on attestations). They still live here
     * so those table migrations never decide whether they may drop a function.
     */
    public function up(): void
    {
        // A month cannot lock while an out is still due: any punch of any
        // workday of this ledger with expected_at after the lock time.
        // 06-attendance.md rule 3 / 07-constraints.md ledgers.
        //
        // INSERT as well as UPDATE OF locked_at: the app role holds INSERT
        // here, so a row written already locked never fires an UPDATE and
        // would never be checked — and a month locked at creation then
        // accumulates workdays whose outs are still pending is exactly the
        // state this exists to forbid. On a genuine firstOrCreate the extra
        // check is free: the ledger has no workdays yet, so the EXISTS is
        // empty.
        //
        // `to_regclass`: workdays and punches arrive in later migrations.
        // A bare FROM against a missing table raises 42P01 when this fires,
        // which would refuse the permitting path (lock with no workdays)
        // that this chunk's tests must see succeed. Once those tables exist
        // the lookups return their oids and the EXISTS runs for real.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledgers_lock_complete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF to_regclass('public.workdays') IS NULL OR to_regclass('public.punches') IS NULL THEN
                    RETURN NEW;
                END IF;

                IF EXISTS (
                    SELECT 1
                      FROM workdays
                      JOIN punches ON punches.workday_id = workdays.id
                     WHERE workdays.ledger_id = NEW.id
                       AND punches.expected_at > NEW.locked_at
                ) THEN
                    RAISE EXCEPTION 'a ledger cannot lock while a punch is still due';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        // Unlocking requires the attestations gone first, on purpose: you
        // certify frozen numbers, never moving ones (06-attendance.md
        // Attestation rule 5). No INSERT limb — a ledger cannot be created
        // with an attestation, since attestations references it and
        // attestations_locked refuses an unlocked parent.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledgers_unlock_clean() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF to_regclass('public.attestations') IS NULL THEN
                    RETURN NEW;
                END IF;

                IF EXISTS (
                    SELECT 1
                      FROM attestations
                     WHERE attestations.ledger_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'a ledger with attestations cannot be unlocked';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        // Decision 55, as rewritten by decision 58. Visibility (decision 30)
        // reads deployment ranges by overlap with a month, so a write that
        // changes which locked months a range covers retroactively changes
        // who could see, attest or correct a month that may already be signed.
        //
        // Coverage, not overlap. An open placement has an unbounded upper
        // bound and therefore overlaps every future month; a rule phrased on
        // overlap would refuse TransferEmployee and RemoveEmployee — both of
        // which merely set `ends` — from the first lock, permanently. Closing
        // today does not change which past months the range covers:
        // [2020-01-01, ∞) and [2020-01-01, 2026-10-15] both cover September
        // 2026. The predicate is the symmetric difference over this
        // employee's locked months: refuse when some locked month is covered
        // by OLD and not NEW, or by NEW and not OLD. INSERT reads OLD
        // coverage as false; DELETE reads NEW coverage as false.
        //
        // `&&` against the calendar month, not `ledgers.month <@ range`.
        // A mid-month placement (15–20 September) covers September without
        // containing the 1st; containment of the first-of-month date would
        // let it through.
        //
        // locked_at IS NOT NULL alone covers "locked or attested":
        // attestations_locked refuses an attestation on an unlocked ledger
        // and ledgers_unlock_clean refuses unlocking an attested one, so
        // attested is a strict subset of locked and a second clause would
        // have no reachable violation.
        //
        // IF / ELSIF on TG_OP, never CASE: plpgsql resolves field references
        // in every CASE arm, and NEW is unassigned in a DELETE trigger —
        // touching it raises rather than yielding null. Return OLD from the
        // DELETE branch and NEW otherwise.
        //
        // Silent on an inverted range (ends < starts): daterange() raises
        // 22000 before deployments_dates_ordered can raise 23514, and a
        // BEFORE ROW trigger always runs ahead of CHECKs. The same shape as
        // agency_not_platform() on a missing agency — another constraint
        // owes the refusal, and raising first would leave it untested.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION deployments_frozen_month() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.ends IS NOT NULL AND OLD.ends < OLD.starts THEN
                        RETURN OLD;
                    END IF;

                    IF EXISTS (
                        SELECT 1
                          FROM ledgers
                         WHERE ledgers.employee_id = OLD.employee_id
                           AND ledgers.locked_at IS NOT NULL
                           AND daterange(OLD.starts, OLD.ends, '[]')
                               && daterange(ledgers.month, (ledgers.month + interval '1 month')::date, '[)')
                    ) THEN
                        RAISE EXCEPTION 'a deployment cannot change which locked months it covers';
                    END IF;

                    RETURN OLD;
                END IF;

                IF NEW.ends IS NOT NULL AND NEW.ends < NEW.starts THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF OLD.ends IS NOT NULL AND OLD.ends < OLD.starts THEN
                        RETURN NEW;
                    END IF;

                    IF EXISTS (
                        SELECT 1
                          FROM ledgers
                         WHERE ledgers.locked_at IS NOT NULL
                           AND ledgers.employee_id IN (OLD.employee_id, NEW.employee_id)
                           AND (
                                (ledgers.employee_id = OLD.employee_id
                                 AND daterange(OLD.starts, OLD.ends, '[]')
                                     && daterange(ledgers.month, (ledgers.month + interval '1 month')::date, '[)'))
                                IS DISTINCT FROM
                                (ledgers.employee_id = NEW.employee_id
                                 AND daterange(NEW.starts, NEW.ends, '[]')
                                     && daterange(ledgers.month, (ledgers.month + interval '1 month')::date, '[)'))
                           )
                    ) THEN
                        RAISE EXCEPTION 'a deployment cannot change which locked months it covers';
                    END IF;

                    RETURN NEW;
                END IF;

                IF EXISTS (
                    SELECT 1
                      FROM ledgers
                     WHERE ledgers.employee_id = NEW.employee_id
                       AND ledgers.locked_at IS NOT NULL
                       AND daterange(NEW.starts, NEW.ends, '[]')
                           && daterange(ledgers.month, (ledgers.month + interval '1 month')::date, '[)')
                ) THEN
                    RAISE EXCEPTION 'a deployment cannot change which locked months it covers';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        // A punch cannot be created against a struck-out timelog
        // (07-constraints.md punches). Silent when the timelog does not
        // exist — the FK owes 23503 — and when timelog_id is null, which is
        // a missed punch. The same IF (SELECT …) shape agency_not_platform()
        // uses so a missing parent is not shadowed with P0001.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION punches_timelog_live() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT voided_at IS NOT NULL FROM timelogs WHERE id = NEW.timelog_id) THEN
                    RAISE EXCEPTION 'a punch cannot use a voided timelog';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        // Attestations are only possible on a locked ledger. Silent when the
        // ledger does not exist: the FK owes 23503. `locked_at IS NULL` is
        // the refusal; a missing row yields NULL, NULL is not true, and the
        // FK then raises.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attestations_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT locked_at IS NULL FROM ledgers WHERE id = NEW.ledger_id) THEN
                    RAISE EXCEPTION 'an attestation requires a locked ledger';
                END IF;

                RETURN NEW;
            END $$;
        SQL);
    }

    /** The one place these are dropped; the argument lists are required. */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS attestations_locked()');
        DB::statement('DROP FUNCTION IF EXISTS punches_timelog_live()');
        DB::statement('DROP FUNCTION IF EXISTS deployments_frozen_month()');
        DB::statement('DROP FUNCTION IF EXISTS ledgers_unlock_clean()');
        DB::statement('DROP FUNCTION IF EXISTS ledgers_lock_complete()');
    }
};
