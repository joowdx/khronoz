<?php

namespace App\Http\Requests;

use App\Support\AttlogParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportTimelogsRequest extends FormRequest
{
    /**
     * `terminals.manage` on *this* terminal, through the gate — matching
     * StoreEnrollmentRequest, and every other write in the application.
     *
     * It used to call `$this->user()->allows(Permission::ManageTerminals)`
     * directly. That reads the permissions column and never reaches
     * `Gate::before`, which is where a platform superuser's authority lives
     * (AppServiceProvider::configureAuthorization); platform users are stored
     * with `permissions = []`, because superuser is the agency flag, not a
     * held permission. So the one path in the application that inserts
     * pay-relevant rows was the one path refusing the operator who may
     * register the terminal and enrol people on it. Reproduced: sibling
     * enrol 302, import 403.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('terminal'));
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
