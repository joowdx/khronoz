<?php

namespace App\Http\Requests;

use App\Support\AttlogParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportTimelogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('terminal'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:32768'],
            'layout' => ['sometimes', Rule::in([AttlogParser::LAYOUT_STANDARD, AttlogParser::LAYOUT_DEVICE])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the attlog file exported from the device.',
            'file.max' => 'That file is larger than 32 MB. Export a narrower date range from the device.',
        ];
    }
}
