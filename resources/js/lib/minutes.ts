/**
 * Minutes as `H:MM`, never as a bare integer.
 *
 * 485 is `8:05`. Zero is an em dash, so a column of ordinary days does not
 * shout. Negative values keep the same clock shape with a minus sign, though
 * the three screens only send counts of minutes owed.
 */
export function formatMinutes(minutes: number): string {
    if (minutes === 0) {
        return '—';
    }

    const absolute = Math.abs(minutes);
    const hours = Math.floor(absolute / 60);
    const remainder = absolute % 60;
    const clock = `${hours}:${remainder.toString().padStart(2, '0')}`;

    return minutes < 0 ? `−${clock}` : clock;
}
