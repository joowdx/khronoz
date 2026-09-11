import { useEffect, useRef } from 'react';
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatDay } from '@/lib/dates';

/**
 * §5.2's third form: a 32px icon button, the month as the page's `<h1>` at
 * 32/38/700 `tabular-nums`, a 32px icon button. It replaces the title in the
 * heading row of a month-scoped page; the breadcrumb bar still shows the
 * plain page name.
 *
 * Changing month re-renders the list. The new month is announced by moving
 * focus to the `<h1>` — a polite live region would also do, an alert would
 * not.
 */
export function MonthStepper({
    value,
    onChange,
}: {
    /** `YYYY-MM`. */
    value: string;
    onChange: (month: string) => void;
}) {
    const heading = useRef<HTMLHeadingElement>(null);
    const skipFocus = useRef(true);

    useEffect(() => {
        if (skipFocus.current) {
            skipFocus.current = false;

            return;
        }

        heading.current?.focus();
    }, [value]);

    return (
        <div className="flex items-center gap-2">
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                aria-label="Previous month"
                onClick={() => onChange(adjacentMonth(value, -1))}
            >
                <ChevronLeftIcon aria-hidden strokeWidth={1.5} />
            </Button>
            <h1
                ref={heading}
                tabIndex={-1}
                className="text-[32px] leading-[38px] font-bold tabular-nums"
            >
                {formatMonthTitle(value)}
            </h1>
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                aria-label="Next month"
                onClick={() => onChange(adjacentMonth(value, 1))}
            >
                <ChevronRightIcon aria-hidden strokeWidth={1.5} />
            </Button>
        </div>
    );
}

/** `YYYY-MM` as "September 2026", via `formatDay` so the month names stay in one place. */
export function formatMonthTitle(value: string): string {
    return formatDay(`${value}-01`).replace(/^\d+\s/, '');
}

function adjacentMonth(value: string, delta: number): string {
    const [year, month] = value.split('-').map(Number);

    if (year === undefined || month === undefined || Number.isNaN(year + month)) {
        return value;
    }

    return new Date(Date.UTC(year, month - 1 + delta, 1)).toISOString().slice(0, 7);
}
