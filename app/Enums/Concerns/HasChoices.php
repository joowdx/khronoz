<?php

namespace App\Enums\Concerns;

trait HasChoices
{
    /** @return array<int, array{value: int|string, label: string}> */
    public static function choices(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
