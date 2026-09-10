<?php

namespace App\Actions;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

final class SeparateEmployee
{
    /**
     * Record that $employee has left: write the change, then close their open
     * placement on the day they left — in one transaction, because a record
     * saying someone has gone must never coexist with a placement saying they
     * are still there.
     *
     * Its own Action rather than a second method on MoveEmployee
     * (.ai/rules/actions.md: a second entry point is a second Action), and a
     * distinct operation from a move: a move opens a replacement row, this
     * one deliberately opens nothing. It is also the only other writer of
     * `deployments.ends` in the application.
     *
     * `ends` is `separated_at` itself, not the day before it the way
     * MoveEmployee closes a superseded row: `deployments_no_overlap` ranges
     * are inclusive on both sides (`daterange(starts, ends, '[]')`), so a
     * move has to leave the incoming row room on the last day while a
     * separation does not — the day someone leaves is a day they were still
     * in that unit, and it is the day their last daily time record belongs
     * to. The value is read back off the model after the update, so the
     * placement is closed on exactly the date the employee row now carries.
     *
     * Only the transition matters, and the caller owns it:
     * EmployeeController::update calls this only when `separated_at` moves
     * from null to a date, so re-saving an already separated employee's form
     * is an ordinary update that touches no deployment. Setting
     * `separated_at` back to null does NOT reopen anything — there is no
     * un-separate operation in this milestone, deliberately: which unit a
     * returning employee belongs to is a decision, not a rollback, and
     * `Move to another unit` already records it.
     *
     * No pre-check on the date, the same relationship MoveEmployee has with
     * `deployments_no_overlap` (R16, R19): a `separated_at` earlier than the
     * open placement's own `starts` leaves `ends < starts` and is refused by
     * `deployments_dates_ordered` (23514). UpdateEmployeeRequest checks that
     * window so the normal mistake reaches the clerk as a message on the
     * field, and EmployeeController::update translates the refusal itself as
     * the backstop; neither replaces the constraint.
     *
     * @param  array<string, mixed>  $attributes  the validated employee attributes, `separated_at` among them
     */
    public function handle(Employee $employee, array $attributes): void
    {
        DB::transaction(function () use ($employee, $attributes): void {
            $employee->update($attributes);

            $employee->currentDeployment?->update(['ends' => $employee->separated_at]);
        });
    }
}
