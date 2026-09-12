<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum EnrollmentPrivilege: string
{
    use HasChoices;

    /** Can punch, and nothing else. Very nearly every enrollment. */
    case User = 'user';

    /** Can register other people's fingerprints on the device. */
    case Enroller = 'enroller';

    /** Can change device settings and manage users. */
    case Admin = 'admin';

    /** Full control of the device, including its comm key. */
    case Superadmin = 'superadmin';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Enroller => 'Enroller',
            self::Admin => 'Administrator',
            self::Superadmin => 'Super administrator',
        };
    }
}
