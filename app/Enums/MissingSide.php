<?php

namespace App\Enums;

/**
 * The `settings.missing_side` key (decision 56, 06-attendance.md daily
 * rule 4). A settings value, not a CHECK on a table, so there is no row in
 * EnumCheckContractTest.
 *
 * **No `label()`, deliberately.** EnumLabelContractTest discovers labelled
 * enums by the presence of `label()`, and a label is UI copy. This key has
 * no screen until Milestone 8; inventing wording now means inventing it
 * twice. HasChoices is omitted for the same reason — it requires `label()`.
 *
 * Default `Void`: a slot missing one side is not worked, and its minutes
 * become tardiness or undertime. `Assume` credits the slot to its expected
 * time and flags the punch for review. A manual timelog or an exemption is
 * the correction path either way.
 */
enum MissingSide: string
{
    /** Treat the slot as not worked; minutes become tardy or undertime. */
    case Void = 'void';

    /** Credit the slot to its expected time and flag the punch for review. */
    case Assume = 'assume';
}
