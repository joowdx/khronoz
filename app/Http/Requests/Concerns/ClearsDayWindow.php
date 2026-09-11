<?php

namespace App\Http\Requests\Concerns;

/**
 * A day window the form stopped offering is cleared, not left behind.
 *
 * `suspensions` and `exemptions` both carry an optional `starts`/`ends` pair
 * meaning "part of the day only", and both forms hide those inputs when the
 * switch is off. An unmounted input submits **nothing**, and an absent key is
 * not a null: `validated()` omits it, `update()` never names the column, and
 * the hours already in the row survive a save the screen reported as
 * successful. Turning "part of the day only" off did nothing at all.
 *
 * The same absence is how extending a one-day exemption across a span reached
 * `exemptions_span_is_whole_days` as a 23514 rather than a message: `until`
 * moved, `starts` stayed, and the CHECK is the first thing that noticed.
 *
 * So the switch itself is submitted, and this reads it. `partial` is the
 * operator's stated intent; `starts`/`ends` are what the intent implies, and
 * where they disagree the intent wins.
 *
 * Absence of `partial` altogether is left alone deliberately — that is a
 * caller which is not one of these forms, and for it `starts`/`ends` mean what
 * they say. Only a `partial` that is present and false clears them.
 */
trait ClearsDayWindow
{
    protected function prepareForValidation(): void
    {
        if ($this->has('partial') && ! $this->boolean('partial')) {
            $this->merge(['starts' => null, 'ends' => null]);
        }
    }

    /**
     * The switch is validated so a malformed value is a 422 rather than a
     * silent false, and `exclude`d so it never reaches the column list —
     * `partial` is a reading of `starts`, not a column of its own.
     *
     * @return array<int, string>
     */
    protected function partialRules(): array
    {
        return ['sometimes', 'boolean', 'exclude'];
    }
}
