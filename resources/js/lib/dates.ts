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

const MANILA_DAY = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

/**
 * Read a `YYYY-MM-DD` string as "1 March 2024".
 *
 * It splits the string and never builds a `Date`, which is the whole point.
 * Every date the API sends is a plain calendar date — `birthdate`, a deployment's
 * `starts` and `ends` — and the resources send
 * them as `YYYY-MM-DD` strings precisely so nothing has to guess an instant
 * for them. `new Date('2024-03-01')` is parsed as UTC midnight and printed in
 * the browser's zone, so west of Greenwich it renders 29 February; that class
 * of off-by-one is what this exists to make impossible.
 *
 * An unrecognisable string comes back unchanged rather than as "Invalid Date":
 * whatever the server sent is more useful on screen than a JavaScript error
 * message.
 */
export function formatDay(iso: string): string {
    const [year, month, day] = iso.split('-');
    const name = MONTHS[Number(month) - 1];

    if (year === undefined || name === undefined || day === undefined) {
        return iso;
    }

    return `${Number(day)} ${name} ${year}`;
}

/**
 * Today in Asia/Manila as `YYYY-MM-DD`, for a date field's default value.
 *
 * The zone is the product's, not the browser's: a daily time record is only
 * meaningful against Philippine Standard Time (hooks/use-manila-clock.ts), and
 * a clerk on a laptop still set to another zone must not default a deployment
 * to the wrong day. Assembled from `formatToParts` rather than trusting a
 * locale to emit ISO order, the same rule the clock follows.
 */
export function manilaToday(): string {
    const parts = Object.fromEntries(
        MANILA_DAY.formatToParts(new Date())
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value]),
    );

    return `${parts.year}-${parts.month}-${parts.day}`;
}

/**
 * The day after a `YYYY-MM-DD` string, as a `YYYY-MM-DD` string.
 *
 * The one place a date the API sent goes through `Date`, and it does so
 * without ever touching a zone: the parts are read out of the string, handed
 * to `Date.UTC` (which normalises 32 January into 1 February for us, month
 * lengths and leap years included), and read back with `toISOString`, which is
 * also UTC. Nothing is parsed from a string and nothing is printed in the
 * browser's zone, so the off-by-one `new Date('2024-03-01')` causes cannot
 * happen here. Rolling the calendar by hand instead would mean shipping a
 * month-length table and a leap-year rule to avoid a `Date` that is already
 * exact.
 *
 * An unrecognisable string comes back unchanged, the way `formatDay` does.
 */
export function addDay(iso: string): string {
    const [year, month, day] = iso.split('-').map(Number);

    if (year === undefined || month === undefined || day === undefined || Number.isNaN(year + month + day)) {
        return iso;
    }

    return new Date(Date.UTC(year, month - 1, day + 1)).toISOString().slice(0, 10);
}

/**
 * The later of two `YYYY-MM-DD` strings.
 *
 * A plain string comparison, which is exactly chronological for zero-padded
 * ISO dates — and is why every date on the wire is one of these.
 */
export function laterDay(a: string, b: string): string {
    return a > b ? a : b;
}
