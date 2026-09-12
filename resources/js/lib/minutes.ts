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
