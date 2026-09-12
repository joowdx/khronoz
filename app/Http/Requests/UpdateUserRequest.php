<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['permissions' => $this->input('permissions', [])]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'permissions' => ['required', 'array', $this->preventsSelfDemotion()],
            'permissions.*' => [Rule::enum(Permission::class)],
        ];
    }

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
