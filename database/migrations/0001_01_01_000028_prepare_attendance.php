<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shared functions live here so table rollbacks never drop another table's function.
     * Use no future-table rowtypes: PL/pgSQL resolves them before those tables exist.
     */
    public function up(): void
    {
        // INSERT is included so an initially locked ledger cannot bypass this guard.
        // `to_regclass` avoids 42P01 until workdays and punches exist.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledgers_lock_complete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- Re-dating a lock requires an explicit unlock to preserve attestations' snapshot meaning.
                IF TG_OP = 'UPDATE' AND OLD.locked_at IS NOT NULL AND OLD.locked_at IS DISTINCT FROM NEW.locked_at THEN
                    RAISE EXCEPTION 'a locked ledger must be unlocked before it can be locked again';
                END IF;

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

        // Attestations certify frozen figures, so they must be removed before unlocking.
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

        // Refuse changes to a locked month's covered days, not mere range overlap.
        // TG_OP uses IF/ELSIF because DELETE has no NEW record; inverted ranges remain silent for their CHECK.
        // Compare month intersections so a re-date cannot erase part of a signed month.
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
                                CASE WHEN ledgers.employee_id = OLD.employee_id
                                     THEN daterange(OLD.starts, OLD.ends, '[]')
                                          * daterange(ledgers.month, (ledgers.month + interval '1 month')::date, '[)')
                                     ELSE 'empty'::daterange END
                                IS DISTINCT FROM
                                CASE WHEN ledgers.employee_id = NEW.employee_id
                                     THEN daterange(NEW.starts, NEW.ends, '[]')
                                          * daterange(ledgers.month, (ledgers.month + interval '1 month')::date, '[)')
                                     ELSE 'empty'::daterange END
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

        // A missing timelog remains silent so its foreign key owns SQLSTATE 23503.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION punches_timelog_live() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT voided_at IS NOT NULL FROM timelogs WHERE id = NEW.timelog_id) THEN
                    RAISE EXCEPTION 'a punch cannot use a voided timelog';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        // A missing ledger remains silent so its foreign key owns SQLSTATE 23503.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attestations_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT locked_at IS NULL FROM ledgers WHERE id = NEW.ledger_id) THEN
                    RAISE EXCEPTION 'an attestation requires a locked ledger';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        // Check both old and new ledgers: moving a workday also rewrites signed history.
        // TG_OP uses IF/ELSIF because DELETE has no NEW record; scalar lookups avoid future-table rowtypes.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION workdays_ledger_open() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF (SELECT locked_at IS NOT NULL FROM ledgers WHERE id = OLD.ledger_id) THEN
                        RAISE EXCEPTION 'a workday cannot be written against a locked ledger';
                    END IF;

                    RETURN OLD;
                END IF;

                IF (SELECT locked_at IS NOT NULL FROM ledgers WHERE id = NEW.ledger_id) THEN
                    RAISE EXCEPTION 'a workday cannot be written against a locked ledger';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.ledger_id IS DISTINCT FROM NEW.ledger_id THEN
                    IF (SELECT locked_at IS NOT NULL FROM ledgers WHERE id = OLD.ledger_id) THEN
                        RAISE EXCEPTION 'a workday cannot be written against a locked ledger';
                    END IF;
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        // Punches need the same lock guard because their own writes bypass a workday-only guard.
        // Cascades can reach this trigger only after the workday guard permits an open ledger.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION punches_ledger_open() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF (SELECT l.locked_at IS NOT NULL FROM workdays w JOIN ledgers l ON l.id = w.ledger_id WHERE w.id = OLD.workday_id) THEN
                        RAISE EXCEPTION 'a punch cannot be written against a locked ledger';
                    END IF;

                    RETURN OLD;
                END IF;

                IF (SELECT l.locked_at IS NOT NULL FROM workdays w JOIN ledgers l ON l.id = w.ledger_id WHERE w.id = NEW.workday_id) THEN
                    RAISE EXCEPTION 'a punch cannot be written against a locked ledger';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.workday_id IS DISTINCT FROM NEW.workday_id THEN
                    IF (SELECT l.locked_at IS NOT NULL FROM workdays w JOIN ledgers l ON l.id = w.ledger_id WHERE w.id = OLD.workday_id) THEN
                        RAISE EXCEPTION 'a punch cannot be written against a locked ledger';
                    END IF;
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        // Freeze exemptions and overtime too: their values contribute to signed month figures.
        // Inverted ranges remain silent so their CHECK constraints own the refusal.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exemptions_frozen_month() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.until < OLD.date THEN
                        RETURN OLD;
                    END IF;

                    IF EXISTS (
                        SELECT 1
                          FROM ledgers
                         WHERE ledgers.employee_id = OLD.employee_id
                           AND ledgers.locked_at IS NOT NULL
                           AND daterange(OLD.date, OLD.until, '[]')
                               && daterange(ledgers.month, (ledgers.month + interval '1 month')::date, '[)')
                    ) THEN
                        RAISE EXCEPTION 'an exemption cannot change which locked months it covers';
                    END IF;

                    RETURN OLD;
                END IF;

                IF NEW.until < NEW.date THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.until < OLD.date THEN
                    RETURN NEW;
                END IF;

                IF EXISTS (
                    SELECT 1
                      FROM ledgers
                     WHERE ledgers.locked_at IS NOT NULL
                       AND ledgers.employee_id IN (OLD.employee_id, NEW.employee_id)
                       AND (
                            (TG_OP = 'UPDATE'
                             AND ledgers.employee_id = OLD.employee_id
                             AND daterange(OLD.date, OLD.until, '[]')
                                 && daterange(ledgers.month, (ledgers.month + interval '1 month')::date, '[)'))
                            OR
                            (ledgers.employee_id = NEW.employee_id
                             AND daterange(NEW.date, NEW.until, '[]')
                                 && daterange(ledgers.month, (ledgers.month + interval '1 month')::date, '[)'))
                       )
                ) THEN
                    RAISE EXCEPTION 'an exemption cannot change which locked months it covers';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION overtimes_frozen_month() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.ends < OLD.starts THEN
                        RETURN OLD;
                    END IF;

                    IF EXISTS (
                        SELECT 1
                          FROM ledgers
                         WHERE ledgers.employee_id = OLD.employee_id
                           AND ledgers.locked_at IS NOT NULL
                           AND daterange(OLD.starts::date, OLD.ends::date, '[]')
                               && daterange(ledgers.month, (ledgers.month + interval '1 month')::date, '[)')
                    ) THEN
                        RAISE EXCEPTION 'an overtime authority cannot change which locked months it covers';
                    END IF;

                    RETURN OLD;
                END IF;

                IF NEW.ends < NEW.starts THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.ends < OLD.starts THEN
                    RETURN NEW;
                END IF;

                IF EXISTS (
                    SELECT 1
                      FROM ledgers
                     WHERE ledgers.locked_at IS NOT NULL
                       AND ledgers.employee_id IN (OLD.employee_id, NEW.employee_id)
                       AND (
                            (TG_OP = 'UPDATE'
                             AND ledgers.employee_id = OLD.employee_id
                             AND daterange(OLD.starts::date, OLD.ends::date, '[]')
                                 && daterange(ledgers.month, (ledgers.month + interval '1 month')::date, '[)'))
                            OR
                            (ledgers.employee_id = NEW.employee_id
                             AND daterange(NEW.starts::date, NEW.ends::date, '[]')
                                 && daterange(ledgers.month, (ledgers.month + interval '1 month')::date, '[)'))
                       )
                ) THEN
                    RAISE EXCEPTION 'an overtime authority cannot change which locked months it covers';
                END IF;

                RETURN NEW;
            END $$;
        SQL);
    }

    /**
     * This is the sole owner of the functions; their argument lists disambiguate the drops.
     */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS workdays_ledger_open()');
        DB::statement('DROP FUNCTION IF EXISTS punches_ledger_open()');
        DB::statement('DROP FUNCTION IF EXISTS exemptions_frozen_month()');
        DB::statement('DROP FUNCTION IF EXISTS overtimes_frozen_month()');
        DB::statement('DROP FUNCTION IF EXISTS attestations_locked()');
        DB::statement('DROP FUNCTION IF EXISTS punches_timelog_live()');
        DB::statement('DROP FUNCTION IF EXISTS deployments_frozen_month()');
        DB::statement('DROP FUNCTION IF EXISTS ledgers_unlock_clean()');
        DB::statement('DROP FUNCTION IF EXISTS ledgers_lock_complete()');
    }
};
