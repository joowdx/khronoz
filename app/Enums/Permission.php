<?php

namespace App\Enums;

use App\Enums\Concerns\HasChoices;

enum Permission: string
{
    use HasChoices;

    case ManageAgency = 'agency.manage';
    case ManageUsers = 'users.manage';
    case ViewOrganization = 'organization.view';
    case ManageOrganization = 'organization.manage';
    case ViewScheduling = 'scheduling.view';
    case ManageScheduling = 'scheduling.manage';
    case ViewCalendar = 'calendar.view';
    case ManageCalendar = 'calendar.manage';
    case ViewTerminals = 'terminals.view';
    case ManageTerminals = 'terminals.manage';
    case ViewLedgers = 'ledgers.view';
    case ManageLedgers = 'ledgers.manage';
    case AttestLedgers = 'ledgers.attest';

    /** @return array<int, self> permissions this one carries with it */
    public function implies(): array
    {
        return match ($this) {
            self::ManageOrganization => [self::ViewOrganization],
            self::ManageScheduling => [self::ViewScheduling],
            self::ManageCalendar => [self::ViewCalendar],
            self::ManageTerminals => [self::ViewTerminals],
            self::ManageLedgers, self::AttestLedgers => [self::ViewLedgers],
            default => [],
        };
    }

    public function grants(self $wanted): bool
    {
        return $this === $wanted || in_array($wanted, $this->implies(), true);
    }

    public function group(): string
    {
        return ucfirst(explode('.', $this->value)[0]);
    }

    public function label(): string
    {
        return match ($this) {
            self::ManageAgency => 'Manage agency profile and settings',
            self::ManageUsers => 'Invite users and set permissions',
            self::ViewOrganization => 'View workgroups and employees',
            self::ManageOrganization => 'Manage workgroups, employees, deployments and tags',
            self::ViewScheduling => 'View shifts, schedules and rosters',
            self::ManageScheduling => 'Manage shifts, schedules and rosters',
            self::ViewCalendar => 'View holidays, suspensions, exemptions and overtime',
            self::ManageCalendar => 'Manage holidays, suspensions, exemptions and overtime',
            self::ViewTerminals => 'View terminals and timelogs',
            self::ManageTerminals => 'Manage terminals, enrollments and timelogs',
            self::ViewLedgers => 'View workdays and DTRs',
            self::ManageLedgers => 'Lock and unlock DTRs',
            self::AttestLedgers => 'Sign DTRs as timekeeper',
        };
    }
}
