import { useEffect, useState } from 'react';

const ZONE = 'Asia/Manila';

export const DAY_OPENS_AT_HOUR = 6;

const stampFormat = new Intl.DateTimeFormat('en-US', {
    timeZone: ZONE,
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

const dayFormat = new Intl.DateTimeFormat('en-US', {
    timeZone: ZONE,
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});

const dateFormat = new Intl.DateTimeFormat('en-US', {
    timeZone: ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

function fields(format: Intl.DateTimeFormat, date: Date): Record<string, string> {
    return Object.fromEntries(
        format
            .formatToParts(date)
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value]),
    );
}

export interface ManilaClock {
    time: string;
    stamp: string;
    day: string;
    date: string;
    position: number;
}

function read(date: Date): ManilaClock {
    const now = fields(stampFormat, date);
    const opened = new Date(date.getTime() - DAY_OPENS_AT_HOUR * 3_600_000);
    const window = fields(dayFormat, opened);
    const stamped = fields(dateFormat, opened);
    const minutesIntoWindow = (Number(now.hour) * 60 + Number(now.minute) - DAY_OPENS_AT_HOUR * 60 + 1440) % 1440;

    return {
        time: `${now.hour}:${now.minute}`,
        stamp: `${now.weekday}, ${now.day} ${now.month} ${now.year}, ${now.hour}:${now.minute}`,
        day: `${window.weekday} ${window.day} ${window.month}`,
        date: `${stamped.year}-${stamped.month}-${stamped.day}`,
        position: minutesIntoWindow / 1440,
    };
}

export function useManilaClock(): ManilaClock {
    const [clock, setClock] = useState<ManilaClock>(() => read(new Date()));

    useEffect(() => {
        let interval: ReturnType<typeof setInterval> | undefined;

        const tick = (): void => setClock(read(new Date()));

        const align = setTimeout(
            () => {
                tick();
                interval = setInterval(tick, 60_000);
            },
            60_000 - (Date.now() % 60_000),
        );

        return () => {
            clearTimeout(align);

            if (interval) {
                clearInterval(interval);
            }
        };
    }, []);

    return clock;
}
