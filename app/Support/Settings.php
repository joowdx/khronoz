<?php

namespace App\Support;

use App\Enums\MissingSide;
use App\Models\Agency;

/**
 * Typed reader over `Agency::$settings` for the seven keys Milestone 6
 * reads (decisions 56 and 83, following decision 15's "one jsonb column,
 * one PHP class").
 *
 * Written without this, each key becomes an inline
 * `$agency->settings['night_from'] ?? '18:00'` at whichever call site needed
 * it first, and the default then disagrees the moment one moves. The shape
 * check, the audit trail and the settings screen stay in Milestone 8;
 * nothing here anticipates them.
 *
 * Takes an `Agency`, never `auth()` or the `Tenant` container binding. The
 * deriver runs from a queued job with no session, the same reason
 * `ImportTimelogs` takes its actor as an argument.
 *
 * A key that is absent, or present as JSON null, returns the default —
 * except `overtime_after_weekly`, where null is meaningful ("no weekly
 * ceiling") and is also the default. That key is stored in **hours** and
 * returned in **minutes**; every other key round-trips as stored.
 */
final class Settings
{
    public function __construct(private readonly Agency $agency) {}

    /** The night window's start, `'HH:MM'`. Default `'18:00'` (RA 11701). */
    public function nightFrom(): string
    {
        return $this->get('night_from') ?? '18:00';
    }

    /**
     * Weekly overtime ceiling in minutes, or null when the rule does not
     * bind (the civil-service case). Stored as hours — the setting is "48"
     * — and returned as minutes (2880).
     */
    public function overtimeAfterWeekly(): ?int
    {
        $hours = $this->get('overtime_after_weekly');

        return $hours === null ? null : (int) $hours * 60;
    }

    /** Monthly occurrence counting (MC 04 s. 1991). Default true. */
    public function occurrences(): bool
    {
        return $this->get('occurrences') ?? true;
    }

    /** Charge the unworked part of a work suspension. Default true. */
    public function suspensionCharge(): bool
    {
        return $this->get('suspension_charge') ?? true;
    }

    /**
     * Credit the first 480 minutes of a premium day (decision 51). Default
     * false: CSC has no premium-regular-hours concept.
     */
    public function premiumHours(): bool
    {
        return $this->get('premium_hours') ?? false;
    }

    /**
     * Whether JC 2 s. 2015 §10's conditions on compensable overtime apply
     * (decision 83). Default true, the civil-service case.
     *
     * They are one instrument's conditions and stand or fall together: a
     * pre-filed authority, arrival on time, at least two hours rendered,
     * and at most twelve paid on a rest day or holiday. The Labor Code has
     * none of them — Art. 87 makes work beyond the prescribed hours
     * overtime by operation of law, Art. 88 forbids offsetting it against
     * undertime, and `../../docs/reference/dole-rules.md` section G records
     * that a private employer authorises overtime by their own act, so the
     * authority row "is optional here".
     *
     * One key and not four, because `dole-rules.md` section H keys each
     * *divergence* rather than each clause, and these four diverge as one.
     * The regime itself is deliberately never stored: khronoz enforces the
     * rule an agency configures and does not adjudicate which law binds
     * them.
     */
    public function overtimeGates(): bool
    {
        return $this->get('overtime_gates') ?? true;
    }

    /** A slot missing one side (daily rule 4). Default `Void`. */
    public function missingSide(): MissingSide
    {
        $value = $this->get('missing_side');

        return $value === null ? MissingSide::Void : MissingSide::from($value);
    }

    private function get(string $key): mixed
    {
        return ($this->agency->settings ?? [])[$key] ?? null;
    }
}
