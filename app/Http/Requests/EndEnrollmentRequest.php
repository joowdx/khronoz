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

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'ends' => ['required', 'date_format:Y-m-d'],
            'expects' => ['present', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<int, callable> */
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

            // lands between here and the write.
            if ($this->date('expects')?->toDateString() !== $enrollment->getRawOriginal('ends')) {
                $validator->errors()->add('ends', 'This enrollment changed since the form was opened. Reload and try again.');
            }
        }];
    }
}
