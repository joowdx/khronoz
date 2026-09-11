<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ends' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
