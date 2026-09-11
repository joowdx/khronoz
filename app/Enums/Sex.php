<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * Mirrors employees.sex (nullable varchar) and the employees_sex_valid CHECK
 * (sex IN ('male', 'female')) read from the live DDL (docs/design/07-constraints.md).
 * The column being null, not a case here, is how "not recorded" is
 * represented, so this enum has no such case.
 */
enum Sex: string
{
    use HasChoices;

    case Male = 'male';
    case Female = 'female';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
        };
    }
}
