<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
     * uniqueness rule below runs. Without this, a submission differing only
     * in case from an existing row would pass validation (Rule::unique's own
     * equality clause compares the raw, un-lowercased input) and then fail
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:254',
                Rule::unique('users', 'email')->where(
                    fn (Builder $query) => $query->whereRaw('lower(email) = ?', [Str::lower($this->string('email'))]),
                ),
            ],
            'permissions' => ['array'],
            'permissions.*' => [Rule::enum(Permission::class)],
        ];
    }

    /**
     * The unique index on users.email is global by design (docs/design/07-
     * constraints.md:103), so Laravel's stock "The email has already been
     * taken." message would confirm — to whoever is filling in this form,
     * for any agency — that the address has an account somewhere in the
     * system. This message is deliberately neutral about *which* agency
     * holds it.
     *
     * Unlike LoginRequest::authenticate() and PasswordResetLinkController::
     * store() (Task 7), it does not close the oracle itself: those two
     * return an identical response whether or not the address is registered,
     * so submitting either tells the caller nothing. Here a taken address
     * still fails validation while an untaken one succeeds, so the response
     * shape alone still reveals existence — only the wording no longer
     * confirms it outright. Accepted at that: this endpoint is authenticated
     * and gated on users.manage (UserPolicy::create()), not an anonymous
     * public form, and an admin creating an account does need to know the
     * address is unavailable.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This address already has an account — ask them to sign in.',
        ];
    }
}
