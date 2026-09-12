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
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'permissions' => $this->input('permissions', []),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
            'permissions' => ['required', 'array'],
            'permissions.*' => [Rule::enum(Permission::class)],
        ];
    }

    /** @return array<int, callable> */
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

    private function addressIsTaken(): bool
    {
        return User::query()
            ->whereRaw('lower(email) = ?', [Str::lower($this->string('email')->toString())])
            ->exists();
    }
}
