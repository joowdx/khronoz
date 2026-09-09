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
 * Every date the API sends is a plain calendar date — `birthdate`, `hired_at`,
 * `separated_at`, a deployment's `starts` and `ends` — and the resources send
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
