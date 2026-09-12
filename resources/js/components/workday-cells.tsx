import { Avatar, AvatarFallback, avatarTint, initials } from '@/components/ui/avatar';
import { StatusPill } from '@/components/ui/badge';
import { TableCell } from '@/components/ui/table';
import { formatMinutes } from '@/lib/minutes';
import type { Choice, Employee, Punch, Workday } from '@/types';

export function Who2({ employee }: { employee: Employee | null | undefined }) {
    const name = employee?.name ?? '—';
    const workgroup = employee?.current_deployment?.workgroup?.name;

    return (
        <span className="flex min-w-0 items-center gap-2.5">
            {employee && (
                <Avatar aria-hidden>
                    <AvatarFallback tint={avatarTint(employee.name)}>{initials(employee.name)}</AvatarFallback>
                </Avatar>
            )}
            <span className="min-w-0">
                <span className="block truncate text-sm leading-[18px] font-medium">{name}</span>
                <span className="text-muted-foreground block truncate text-xs leading-4">{workgroup ?? '—'}</span>
            </span>
        </span>
    );
}

export function PunchChain({ punches, date }: { punches: Punch[] | undefined; date?: string }) {
    if (punches === undefined || punches.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span className="tabular-nums">
            {punches.map((punch, index) => (
                <span key={punch.id}>
                    {index > 0 && ' · '}
                    {punch.actual_at ? (
                        <span title={signedDeviation(punch.deviation)}>
                            {punch.actual_at.slice(11, 16)}
                            <DayMarker offset={dayOffset(date, punch.actual_at)} />
                        </span>
                    ) : (
                        <span className="text-attention">—</span>
                    )}
                </span>
            ))}
        </span>
    );
}

function dayOffset(date: string | undefined, at: string): number {
    if (date === undefined) {
        return 0;
    }

    const days = (Date.parse(`${at.slice(0, 10)}T00:00:00Z`) - Date.parse(`${date}T00:00:00Z`)) / 86_400_000;

    return Number.isFinite(days) ? Math.round(days) : 0;
}

function DayMarker({ offset }: { offset: number }) {
    if (offset === 0) {
        return null;
    }

    const digits = Math.abs(offset)
        .toString()
        .split('')
        .map((digit) => SUPERSCRIPT[Number(digit)])
        .join('');

    return (
        <>
            <span aria-hidden className="text-muted-foreground">
                {offset > 0 ? '⁺' : '⁻'}
                {digits}
            </span>
            <span className="sr-only">{offset > 0 ? ` (next day)` : ` (previous day)`}</span>
        </>
    );
}

const SUPERSCRIPT = ['⁰', '¹', '²', '³', '⁴', '⁵', '⁶', '⁷', '⁸', '⁹'];

export function WorkdayStatus({ status, premium }: { status: Choice; premium?: Choice | null }) {
    return (
        <span className="flex items-center gap-2">
            <StatusPill variant={statusVariant(status.value)}>{status.label}</StatusPill>
            {premium && <span className="text-muted-foreground text-xs leading-4">{premium.label}</span>}
        </span>
    );
}

export function MinuteCells({ workday }: { workday: Workday | null }) {
    const figures = workday
        ? [workday.worked, workday.tardy, workday.undertime, workday.excess, workday.night]
        : [0, 0, 0, 0, 0];

    return (
        <>
            {figures.map((minutes, index) => (
                <TableCell key={index} numeric>
                    {formatMinutes(minutes)}
                </TableCell>
            ))}
        </>
    );
}

function statusVariant(value: string): 'positive' | 'destructive' | 'attention' | 'secondary' {
    switch (value) {
        case 'present':
        case 'remote':
            return 'positive';
        case 'absent':
            return 'destructive';
        case 'holiday':
        case 'exempt':
        case 'suspended':
            return 'attention';
        default:
            return 'secondary';
    }
}

function signedDeviation(deviation: number | null): string | undefined {
    if (deviation === null) {
        return undefined;
    }

    if (deviation === 0) {
        return '0';
    }

    return deviation > 0 ? `+${deviation}` : `−${Math.abs(deviation)}`;
}
