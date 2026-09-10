<?php

namespace App\Http\Requests;

use App\Enums\Sex;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    /** Default a wholly-unchecked tag list to [], the same way StoreEmployeeRequest does. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'tags' => $this->input('tags', []),
            'exempt' => $this->boolean('exempt'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'number' => ['required', 'string', 'max:255', Rule::unique('employees', 'number')->where('agency_id', $employee->agency_id)->ignore($employee->id)],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:255'],
            'sex' => ['nullable', Rule::enum(Sex::class)],
            'birthdate' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:254'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:255'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:40', 'distinct'],
            'exempt' => ['boolean'],
            'hired_at' => ['required', 'date'],
            'separated_at' => ['nullable', 'date', 'after_or_equal:hired_at'],
        ];
    }

    /**
     * Separating an employee closes their open placement on `separated_at`
     * (App\Actions\SeparateEmployee), so a date earlier than that placement's
     * own `starts` would leave `ends < starts` and be refused by
     * `deployments_dates_ordered` with SQLSTATE 23514 — a 500 rather than a
     * message on the field a clerk just typed.
     *
     * This is a rule, not a replacement for the constraint (R19): the
     * database stays the last word, EmployeeController::update translates its
     * refusal as the backstop, and this exists so the normal mistake —
     * backdating a separation past the current placement — arrives as a
     * message. It mirrors MoveEmployeeRequest's hire-window check, which
     * carries the same shape for the same reason on `starts`, and reuses that
     * controller's own wording for the identical condition.
     *
     * Only the transition null → a date is checked: an employee who already
     * has a separation date has no open placement left for this to be about,
     * and clearing the date reopens nothing (SeparateEmployee's docblock).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('separated_at') || $this->date('separated_at') === null) {
                return;
            }

            /** @var Employee $employee */
            $employee = $this->route('employee');

            if ($employee->separated_at !== null) {
                return;
            }

            $starts = $employee->currentDeployment?->starts;

            if ($starts !== null && $this->date('separated_at')->lt($starts)) {
                $validator->errors()->add('separated_at', 'Before the current placement began.');
            }
        }];
    }
}
