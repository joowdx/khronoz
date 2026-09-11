<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A `23505` re-raised as the field error its `Rule::unique` would have given.
 *
 * Every unique constraint a form can reach **is** mirrored in validation, and
 * each mirror has a duplicate-value test — the four-way audit's claim that
 * "nothing catches 23505" is not a live hole in that sense. What it is, is the
 * one SQLSTATE this application does not translate, while it translates 23514,
 * 23P01, 23001, 428C9 and P0001 everywhere. That asymmetry has a cost of
 * exactly one shape: a `Rule::unique` is a check followed by a write, so two
 * administrators submitting the same code at the same moment both pass
 * validation and the loser gets a 500 instead of `Already taken`.
 *
 * The window is small and the action is rare. It is also the cheapest possible
 * fix, and a 500 on a duplicate gets reported as "the system broke" rather
 * than "somebody got there first".
 *
 * The message comes from the same place the mirror's would:
 * `lang/en/validation.php` answers `unique` with `Already taken`, so the two
 * paths cannot drift apart.
 */
trait TranslatesUniqueCollisions
{
    /**
     * Run $write, turning a unique violation into a validation error on
     * whichever of $fields the constraint name mentions.
     *
     * Ordering matters when a table carries more than one unique: the first
     * matching fragment wins. A violation of a constraint not listed is
     * rethrown rather than reported as a field error on a field it has nothing
     * to do with — an unrecognised collision is a bug, and a 500 is the honest
     * answer to it.
     *
     * @param  array<string, string>  $fields  constraint-name fragment => field name
     */
    protected function translatingCollisions(array $fields, callable $write): mixed
    {
        try {
            // Its own transaction, so the refusal is **recoverable** rather
            // than leaving the surrounding one aborted — the same reasoning
            // TerminalController::destroy records for its 23001. Without it a
            // rejected insert poisons the connection and the very next query,
            // including the redirect's own, fails 25P02. Nested inside an
            // outer transaction this is a SAVEPOINT, which is exactly the
            // scope that needs rolling back.
            return DB::transaction($write);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            foreach ($fields as $fragment => $field) {
                if (str_contains($e->getMessage(), $fragment)) {
                    throw ValidationException::withMessages([
                        $field => [trans('validation.unique', ['attribute' => $field])],
                    ]);
                }
            }

            throw $e;
        }
    }
}
