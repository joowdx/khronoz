<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreAgencyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The `platform` middleware on the route group is the actual gate here;
     * every user who reaches this request is already a platform user.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Upper-case the code before it is validated, so the uniqueness rule
     * below and the stored value both see the same normalised form — a
     * lower-case 'doh' must collide with an existing 'DOH'.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => Str::upper((string) $this->input('code'))]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16', 'alpha_dash', Rule::unique('agencies', 'code')],
            'name' => ['required', 'string', 'max:120'],
        ];
    }
}
