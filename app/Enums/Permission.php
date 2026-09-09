<?php

namespace App\Enums;

/**
 * A permission a user can hold. Access is a set of these on the user, not a
 * role (docs/design/02-access.md rule 4) — the platform flag makes a
 * superuser, not a permission in this set.
 *
 * The string on each case crosses the wire as an Inertia prop and is compared
 * in the browser by useCan(), and is also what permissions_valid() guards in
 * the users.permissions column: it is a contract with
 * resources/js/types/index.d.ts and must match that TypeScript union exactly.
 */
enum Permission: string
{
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

    /**
     * @return array<int, self> permissions this one carries with it
     *
     * Mirrored by the `implied` map in resources/js/hooks/use-can.ts, which
     * cannot import this enum and so restates every edge by hand — add a case
     * here and add its match there, or useCan() will disagree with this gate.
     */
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
            self::ViewOrganization => 'View units and employees',
            self::ManageOrganization => 'Manage units, employees, deployments and groups',
            self::ViewScheduling => 'View shifts, schedules and rosters',
            self::ManageScheduling => 'Manage shifts, schedules and rosters',
            self::ViewCalendar => 'View holidays, suspensions, exemptions and overtime',
            self::ManageCalendar => 'Manage holidays, suspensions, exemptions and overtime',
            self::ViewTerminals => 'View terminals and timelogs',
            self::ManageTerminals => 'Manage terminals, enrollments and timelogs',
            self::ViewLedgers => 'View workdays and DTRs',
            self::ManageLedgers => 'Lock and unlock DTRs',
            self::AttestLedgers => 'Sign DTRs as HR',
        };
    }
}
