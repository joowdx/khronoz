const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
] as const;

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as const;

const MANILA_DAY = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

export function formatDay(iso: string): string {
    const [year, month, day] = iso.split('-');
    const name = MONTHS[Number(month) - 1];

    if (year === undefined || name === undefined || day === undefined) {
        return iso;
    }

    return `${Number(day)} ${name} ${year}`;
}

export function formatDayWithWeekday(iso: string): string {
    const [year, month, day] = iso.split('-').map(Number);
    const weekday =
        year === undefined || month === undefined || day === undefined || Number.isNaN(year + month + day)
            ? undefined
            : WEEKDAYS[new Date(Date.UTC(year, month - 1, day)).getUTCDay()];

    if (weekday === undefined) {
        return formatDay(iso);
    }

    return `${weekday} ${formatDay(iso)}`;
}

export function formatShortDay(iso: string): string {
    const [, month, day] = iso.split('-');
    const name = MONTHS[Number(month) - 1];

    if (name === undefined || day === undefined) {
        return iso;
    }

    return `${Number(day)} ${name.slice(0, 3)}`;
}

export function manilaToday(): string {
    const parts = Object.fromEntries(
        MANILA_DAY.formatToParts(new Date())
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value]),
    );

    return `${parts.year}-${parts.month}-${parts.day}`;
}

export function addDay(iso: string): string {
    const [year, month, day] = iso.split('-').map(Number);

    if (year === undefined || month === undefined || day === undefined || Number.isNaN(year + month + day)) {
        return iso;
    }

    return new Date(Date.UTC(year, month - 1, day + 1)).toISOString().slice(0, 10);
}

export function laterDay(a: string, b: string): string {
    return a > b ? a : b;
}
