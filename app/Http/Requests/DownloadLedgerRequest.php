<?php

namespace App\Http\Requests;

use App\Enums\Period;
use App\Enums\ReportDay;
use App\Enums\Work;
use App\Models\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DownloadLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($ledger = $this->route('ledger')) {
            return $this->user()->can('view', $ledger);
        }
        $employee = $this->route('employee');

        return $this->user()->can('view', new Ledger(['agency_id' => $employee->agency_id, 'employee_id' => $employee->id]));
    }

    public function rules(): array
    {
        return [
            'starts' => [Rule::requiredIf($this->route('ledger') === null), 'date_format:Y-m-d'],
            'ends' => [Rule::requiredIf($this->route('ledger') === null), 'date_format:Y-m-d', 'after_or_equal:starts'],
            'period' => ['sometimes', Rule::enum(Period::class)],
            'work' => ['sometimes', Rule::enum(Work::class)],
            'days' => ['sometimes', 'array', 'list', 'max:3'],
            'days.*' => ['required', Rule::enum(ReportDay::class), 'distinct'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('starts') || ! $this->filled('ends')) {
                return;
            }
            if (CarbonImmutable::parse($this->input('starts'))->diffInDays(CarbonImmutable::parse($this->input('ends'))) > 30) {
                $validator->errors()->add('ends', 'Choose no more than 31 days.');
            }
        }];
    }
}
