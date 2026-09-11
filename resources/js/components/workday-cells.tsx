import { Avatar, AvatarFallback, avatarTint, initials } from '@/components/ui/avatar';
import { StatusPill } from '@/components/ui/badge';
import { TableCell } from '@/components/ui/table';
import { formatMinutes } from '@/lib/minutes';
import type { Choice, Employee, Punch, Workday } from '@/types';

/**
 * `.who2`: avatar, name at 14/18/500, workgroup at 12/16 muted.
 *
 * Workgroup comes from `current_deployment` when the employee payload carries
 * it. The workdays and ledgers indexes load the employee without that
 * relation, so the second line is an em dash until a later chunk asks for it.
 */
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

/**
 * The day's punches as `07:58 · 12:03 · — · 17:05`.
 *
 * A missing side is daily rule 4 left in the data on purpose: never hidden,
 * never a blank, never a zero. The dash takes the attention colour. Each
 * punched time carries its signed deviation as a `title`.
 */
export function PunchChain({ punches }: { punches: Punch[] | undefined }) {
    if (punches === undefined || punches.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span className="tabular-nums">
            {punches.map((punch, index) => (
                <span key={punch.id}>
                    {index > 0 && ' · '}
                    {punch.actual_at ? (
                        <span title={signedDeviation(punch.deviation)}>{punch.actual_at.slice(11, 16)}</span>
                    ) : (
                        <span className="text-attention">—</span>
                    )}
                </span>
            ))}
        </span>
    );
}

export function WorkdayStatus({ status, premium }: { status: Choice; premium?: Choice | null }) {
    return (
        <span className="flex items-center gap-2">
            <StatusPill variant={statusVariant(status.value)}>{status.label}</StatusPill>
            {premium && <span className="text-muted-foreground text-xs leading-4">{premium.label}</span>}
        </span>
    );
}

/** Worked · Tardy · Undertime · Excess · Night, each `H:MM` or an em dash. */
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

/**
 * Colour families for a workday status. The *words* stay on the Choice the
 * server sent; this only picks the pill's tint, and an unknown value falls
 * through to neutral so a new case is still readable.
 */
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
