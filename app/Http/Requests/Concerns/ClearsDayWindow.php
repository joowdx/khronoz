<?php

namespace App\Http\Requests\Concerns;

trait ClearsDayWindow
{
    protected function prepareForValidation(): void
    {
        if ($this->has('partial') && ! $this->boolean('partial')) {
            $this->merge(['starts' => null, 'ends' => null]);
        }
    }

    /** @return array<int, string> */
    protected function partialRules(): array
    {
        return ['sometimes', 'boolean', 'exclude'];
    }
}
