<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * Normalise email the same way User's own mutator does, before the
     * uniqueness check below runs. Without this, a submission differing only
     * in case from an existing row would pass validation and then fail
     * loudly at the database instead, since users_email is a unique index on
     * lower(email).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'permissions' => $this->input('permissions', []),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * An account with no permissions can sign in and reach nothing, so the
     * matrix is required rather than merely well-formed — `Choose at least
     * one` (lang/en/validation.php's `custom.permissions.required`) is the
     * error the design draws for it.
     *
     * Uniqueness is checked in after() instead of by Rule::unique, because
     * this form answers a taken address twice: the short verdict on the
     * field's label row and the sentence that says what to do about it.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
            'permissions' => ['required', 'array'],
            'permissions.*' => [Rule::enum(Permission::class)],
        ];
    }

    /**
     * The unique index on users.email is global by design (docs/design/07-
     * constraints.md:103), so the address may already belong to an account
     * in another agency — one this form's submitter cannot see and must not
     * be told about. Both messages are therefore silent about *which*
     * agency holds it:
     *
     * | Key              | Renders as                        | Says |
     * | ---------------- | --------------------------------- | ---- |
     * | `email`          | the field's label row (§6.1)      | `Already taken` |
     * | `email_conflict` | a banner under the field (§6.4)   | the sentence, whose fix is a link the page supplies |
     *
     * `email_conflict` is not a field of this form, which is the convention
     * for a message that is not a field's own one-line verdict.
     *
     * It does not close the oracle itself: unlike LoginRequest::
     * authenticate() and PasswordResetLinkController::store(), which answer
     * identically whether or not an address is registered, a taken address
     * still fails here while an untaken one succeeds — the response shape
     * alone reveals existence. Accepted at that: this endpoint is
     * authenticated and gated on users.manage (UserPolicy::create()), not an
     * anonymous public form, and an administrator does need to know the
     * address is unavailable.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // A malformed or missing address has already been answered; a
            // second verdict on the same row would only compete with it.
            if ($validator->errors()->has('email') || ! $this->addressIsTaken()) {
                return;
            }

            $validator->errors()->add('email', trans('validation.unique'));
            $validator->errors()->add('email_conflict', 'This address already has an account.');
        }];
    }

    /**
     * Matches the index the database enforces — `lower(email)`, across every
     * agency — so a duplicate is caught here rather than as an uncaught
     * constraint violation. User carries no tenant scope, which is what
     * makes this query global.
     */
    private function addressIsTaken(): bool
    {
        return User::query()
            ->whereRaw('lower(email) = ?', [Str::lower($this->string('email')->toString())])
            ->exists();
    }
}
