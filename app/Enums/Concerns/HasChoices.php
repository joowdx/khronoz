<?php

namespace App\Enums\Concerns;

/**
 * The `{value, label}` list a picker needs, built from the cases themselves.
 *
 * `.ai/rules/resources.md` requires that a page never hold an enum's
 * vocabulary — the cases are held to the database CHECK by
 * `EnumCheckContractTest`, and a copy in TypeScript is held to nothing and
 * drifts. The rule was recorded after `resources/js/lib/calendar.ts` dropped
 * a case and invented one, and then five more restatements were found in the
 * front end by the four-way audit, because writing the map out by hand in
 * every controller is just friction enough to be skipped.
 *
 * So the list comes from the enum. Adding a case adds the option, everywhere,
 * with no second place to remember.
 *
 * Requires a `label(): string` on the enum. Ordering is declaration order,
 * which is deliberate: the cases are written in the order an operator should
 * meet them, not alphabetically.
 */
trait HasChoices
{
    /** @return array<int, array{value: string, label: string}> */
    public static function choices(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
