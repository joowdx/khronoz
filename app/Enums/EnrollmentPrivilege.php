<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

/**
 * Mirrors enrollments.privilege (varchar) and the enrollments_privilege_valid
 * CHECK (privilege IN ('user', 'enroller', 'admin', 'superadmin')) —
 * docs/design/07-constraints.md.
 *
 * A **string** enum where `timelogs.state` and `timelogs.mode` are raw
 * integers, and the difference is deliberate. Those two are values the device
 * *reports*, so 03-terminals.md rule 6 keeps them as the attlog's own ints and
 * casts with `tryFrom` — an unknown value from an unfamiliar firmware must
 * survive. This is a role khronoz *assigns* to a person on a device, written
 * by the enrollment writer and read back by it, so the vendor's numbering
 * (0, 2, 6, 12, 14 across ZKTeco firmwares) is an implementation detail of the
 * push and pull protocols to translate at the boundary, not a value to store.
 */
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
