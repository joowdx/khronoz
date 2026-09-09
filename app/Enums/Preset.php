<?php

namespace App\Enums;

/**
 * A convenience bundle of permissions for the invite form. Presets exist only
 * in code — they expand to a plain permission list at creation time and are
 * never themselves persisted (docs/design/02-access.md rule 4).
 */
enum Preset: string
{
    case Admin = 'admin';
    case Hr = 'hr';
    case Viewer = 'viewer';

    /** @return array<int, Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Hr => [
                Permission::ManageOrganization,
                Permission::ManageScheduling,
                Permission::ManageCalendar,
                Permission::ManageTerminals,
                Permission::ManageLedgers,
                Permission::AttestLedgers,
            ],
            self::Viewer => [
                Permission::ViewOrganization,
                Permission::ViewScheduling,
                Permission::ViewCalendar,
                Permission::ViewTerminals,
                Permission::ViewLedgers,
            ],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Hr => 'HR',
            self::Viewer => 'Viewer',
        };
    }
}
