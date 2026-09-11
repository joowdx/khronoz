import { useEffect, useState } from 'react';

/**
 * The product's clock. Every agency this product serves keeps its records in
 * Philippine Standard Time, and a daily time record is only meaningful against
 * one zone, so the clock reads `Asia/Manila` wherever the browser happens to
 * be.
 *
 * `Intl.DateTimeFormat` does the conversion: the instant stays a plain `Date`
 * and only the *reading* of it is zoned, so there is no shadow timezone to keep
 * in step and nothing to add to package.json.
 */
const ZONE = 'Asia/Manila';

/** A day opens at 06:00 and closes at 30:00 — the product's one time scale. */
export const DAY_OPENS_AT_HOUR = 6;

/**
 * Every string is assembled from `formatToParts` rather than taken from a
 * locale's own pattern, because no locale produces what the artboards draw:
 * `en-GB` abbreviates September to "Sept" and joins the date to the time with
 * " at ", `en-US` puts the month first. Parts give the field values; the order
 * and the punctuation are ours.
 *
 * `hourCycle: 'h23'` rather than `hour12: false`: the latter renders midnight
 * as "24" under some locales, which would push the now-marker off the end of
 * the ruler for the one minute a day it matters most.
 *
 * All three formatters are built once. Constructing an `Intl.DateTimeFormat`
 * is the expensive half of formatting, and this runs every minute for as long
 * as the tab is open.
 */
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

/**
 * The same instant as `dayFormat`, in the one shape the rest of the
 * application speaks: zero-padded `YYYY-MM-DD`. Every date on the wire is that
 * string (`lib/dates.ts`), so a value read off this clock can be compared with
 * one the server sent by plain string comparison and never through `Date`.
 */
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
    /** `14:42` — the wall clock in Manila. */
    time: string;
    /** `Wednesday, 9 September 2026, 14:42` — the dashboard's status line. */
    stamp: string;
    /**
     * `Wed 9 Sep` — the day strip's label. This is the date of the *day window*
     * that is open, not the calendar date: a day runs 06:00 to 30:00, so at
     * 02:00 the open window is still yesterday's, and stamping it with today's
     * date would put the marker four fifths of the way along a day that had
     * barely started. Which workday a punch finally belongs to is settled by
     * `Workday` in Milestone 6; this is the same rule, read off the clock.
     */
    day: string;
    /**
     * `2026-09-09` — the same day as `day`, zero-padded, for anything that has
     * to compare it with a date the server sent.
     *
     * The **day-window** date, not the calendar date, for exactly the reason
     * `day` is: at 02:00 the open window is still yesterday's. So this is the
     * date a punch taken now would belong to, which is what makes it worth
     * having as a string — `DashboardController` computes the same date server
     * side (six hours back, then the date) and sends it as `duty.date`, and the
     * two must be able to disagree visibly rather than silently.
     */
    date: string;
    /** Where the now-marker sits on the 06:00 → 30:00 ruler, 0 to 1. */
    position: number;
}

function read(date: Date): ManilaClock {
    const now = fields(stampFormat, date);
    // One shifted instant read twice, never two subtractions: the label and
    // the string have to name the same day at every moment, including the one
    // minute a day when the window turns over.
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

/**
 * The Manila clock, re-read on the minute.
 *
 * It ticks on the minute rather than the second because nothing on screen
 * shows seconds, and it aligns the first tick to the next minute boundary so
 * the displayed minute changes when the minute does instead of up to 59
 * seconds late. Both timers are cleared on unmount.
 *
 * Nothing here animates. A status indicator that moves is forbidden
 * (docs/design/08-interface.md §1 rule 7), so the marker and the label jump
 * once a minute and there is nothing for `prefers-reduced-motion` to switch
 * off.
 */
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
