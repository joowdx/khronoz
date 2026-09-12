<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class RetireCadenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ManageAgency->value);
    }

    public function rules(): array
    {
        return [];
    }
}
