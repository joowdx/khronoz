<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Support\AttlogParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportTimelogsRequest extends FormRequest
{
    /**
     * `terminals.manage`, which already exists and is already mirrored in the
     * TypeScript union and the implies() map (PermissionContractTest enforces
     * that). Viewing timelogs is a narrower right than adding them.
     */
    public function authorize(): bool
    {
        return $this->user()->allows(Permission::ManageTerminals);
    }

    /**
     * `mimes` is deliberately absent and `extensions` is deliberately loose.
     *
     * An attlog export is a plain text file that arrives as `.dat`, `.txt`,
     * `.csv` or with no extension at all, and PHP's MIME detection reports
     * `text/plain` or `application/octet-stream` more or less at random for
     * the same bytes. A rule strict enough to be meaningful here would reject
     * genuine exports, and it would buy nothing: the parser validates every
     * field of every line and rejects what it cannot read, which is a far
     * stronger guarantee than a filename suffix.
     *
     * The size cap is the real protection, and it is generous — a year of
     * punches for a large agency is a few megabytes of text.
     *
     * @return array<string, mixed>
     */
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
