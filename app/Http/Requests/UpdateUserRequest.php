<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /** Default a wholly-unchecked permission set to [], the same way StoreUserRequest does. */
    protected function prepareForValidation(): void
    {
        $this->merge(['permissions' => $this->input('permissions', [])]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Email is intentionally absent: it is not editable in v1.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'permissions' => ['array', $this->preventsSelfDemotion()],
            'permissions.*' => [Rule::enum(Permission::class)],
        ];
    }

    /**
     * UserPolicy::delete() refuses a user removing their own account, but
     * that guard needs only identity. Its update() counterpart can't be
     * expressed the same way in the policy: the policy sees the acting user
     * and the target, never the submitted permissions, so "would this
     * request drop users.manage from myself" isn't answerable there without
     * bolting a payload-shaped argument onto an otherwise pure ability
     * check. It belongs here instead, where the submitted permissions are
     * already in scope.
     *
     * Only fires on a genuine self-edit — a users.manage holder editing a
     * colleague may still submit any permission set, including one that
     * drops the colleague's own users.manage.
     */
    protected function preventsSelfDemotion(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $editingSelf = $this->user()->is($this->route('user'));

            if ($editingSelf && is_array($value) && ! in_array(Permission::ManageUsers->value, $value, true)) {
                $fail('You cannot remove your own user management access.');
            }
        };
    }
}
