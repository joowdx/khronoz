<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EndEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('terminal'));
    }

    /**
     * Ending is the only edit this screen offers, and deliberately.
     *
     * An enrollment's `uid`, `terminal_id` and `employee_id` *can* be changed
     * — decision 43 made that possible on purpose, so a mistyped device user
     * id is correctable — but changing them re-attributes punches, which is a
     * different and heavier act than "this person stopped using this device".
     * That correction belongs behind its own screen with its own warning, not
     * on the row that also holds the everyday verb.
     *
     * `expects` is the compare-and-swap predicate this endpoint was missing.
     * It is the same operation on the same shape of data as
     * EndEmployeeDeploymentRequest — closing a dated range from a form that
     * may be stale — and that request has carried `expects` since decision 30
     * reclassified the hole as a security defect. Here the route binding
     * already answers "which row", so `expects` is the whole predicate: it
     * carries the row's `ends` as the page rendered it, empty for an open
     * enrollment, so a concurrent change is detected rather than overwritten.
     *
     * Without it a form opened before someone else closed the enrollment,
     * submitted after, silently moved an end date already on the record — and
     * an enrollment's range is what attributes punches to a person, so the
     * rewrite reattributes pay.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ends' => ['required', 'date_format:Y-m-d'],
            'expects' => ['present', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Give the field errors before writing. The controller still translates
     * the database's refusals: a row that is no longer the one this form saw
     * is an operation error handled by the conditional UPDATE, not a field
     * error about the date the operator entered.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $enrollment = $this->route('enrollment');

            if ($this->date('ends')->lt($enrollment->starts)) {
                $validator->errors()->add('ends', 'An enrollment cannot end before it starts.');
            }

            // `expects` must be this row's own current `ends`, or the
            // predicate below would miss and the operator would be told the
            // row "changed while you were looking at it" for a request that
            // was simply wrong about it — a message that would be a lie. The
            // same overclaim was caught on the deployment endpoint, and the
            // check does not make the predicate redundant: this catches a
            // stale or fabricated form, the predicate catches a change that
            // lands between here and the write.
            if ($this->date('expects')?->toDateString() !== $enrollment->getRawOriginal('ends')) {
                $validator->errors()->add('ends', 'This enrollment changed since the form was opened. Reload and try again.');
            }
        }];
    }
}
